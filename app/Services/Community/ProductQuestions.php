<?php

namespace App\Services\Community;

use App\Models\Product;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Notifications\NewQuestionNotification;
use App\Notifications\QuestionAnsweredNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product questions and answers.
 *
 *  - Only signed-in people ask, never the seller of that product, at most one question per person per product per
 *    day, 10 to 500 characters.
 *  - A question carrying a link, an e-mail address or a phone number is held for the operator instead of going live
 *    (those are how spam and off-platform deals arrive); everything else is published at once.
 *  - Only the store's own people answer (StoreMembers). A seller cannot delete or hide an answer; only the operator
 *    can hide one, and the asker can withdraw their own question while it has no answer.
 */
class ProductQuestions
{
    public function __construct(private readonly StoreMembers $members) {}

    /**
     * @throws ValidationException
     */
    public function ask(User $user, Product $product, string $body): ProductQuestion
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        $min = (int) config('discovery.qa.min_question_length', 10);
        $max = (int) config('discovery.qa.max_question_length', 500);

        if (mb_strlen($body) < $min || mb_strlen($body) > $max) {
            throw ValidationException::withMessages(['body' => "Ask in {$min} to {$max} characters."]);
        }

        if (! Product::query()->browsable()->whereKey($product->id)->exists()) {
            throw ValidationException::withMessages(['body' => 'This product is not available.']);
        }

        if ($this->members->canSpeakFor($user, $product->store)) {
            throw ValidationException::withMessages(['body' => 'You sell this product. Answer questions instead of asking them.']);
        }

        $question = DB::transaction(function () use ($user, $product, $body) {
            // Serialise a person's own double click on the same product.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $recent = ProductQuestion::query()->where('user_id', $user->id)->where('product_id', $product->id)
                ->where('created_at', '>', now()->subDay())->exists();

            if ($recent) {
                throw ValidationException::withMessages(['body' => 'You can ask one question about a product per day. Try again tomorrow.']);
            }

            $held = $this->suspicious($body);

            $question = new ProductQuestion(['product_id' => $product->id, 'user_id' => $user->id, 'body' => $body]);
            $question->forceFill(['status' => $held ? ProductQuestion::HELD : ProductQuestion::PUBLISHED, 'held_reason' => $held ? 'link_or_contact_details' : null])->save();

            return $question;
        });

        if ($question->status === ProductQuestion::PUBLISHED) {
            $product->store->owner?->notify(new NewQuestionNotification($question, $product));
        }

        return $question;
    }

    /**
     * @throws ValidationException
     */
    public function answer(User $user, ProductQuestion $question, string $body): ProductAnswer
    {
        $question->loadMissing('product.store');
        $store = $question->product->store;
        $body = trim($body);

        if (! $this->members->canSpeakFor($user, $store)) {
            throw ValidationException::withMessages(['body' => 'Only the store that sells this product can answer.']);
        }

        if ($question->status !== ProductQuestion::PUBLISHED) {
            throw ValidationException::withMessages(['body' => 'This question is not open for answers.']);
        }

        $max = (int) config('discovery.qa.max_answer_length', 1500);

        if ($body === '' || mb_strlen($body) > $max) {
            throw ValidationException::withMessages(['body' => "Write an answer of up to {$max} characters."]);
        }

        $answer = DB::transaction(function () use ($question, $user, $store, $body) {
            $answer = new ProductAnswer(['question_id' => $question->id, 'user_id' => $user->id, 'store_id' => $store->id, 'body' => $body, 'is_seller' => true]);
            $answer->save();

            $question->forceFill(['answers_count' => $question->answers()->published()->count()])->save();

            return $answer;
        });

        $question->user?->notify(new QuestionAnsweredNotification($question, $question->product));

        return $answer;
    }

    /** The asker withdraws their own unanswered question. */
    public function withdraw(ProductQuestion $question): void
    {
        if ($question->answers()->published()->exists()) {
            throw ValidationException::withMessages(['question' => 'A question that has been answered cannot be withdrawn.']);
        }

        $question->delete();
    }

    /** Operator only. */
    public function hideQuestion(User $operator, ProductQuestion $question): void
    {
        $this->requireOperator($operator);
        $question->forceFill(['status' => ProductQuestion::HIDDEN, 'moderated_by' => $operator->id, 'moderated_at' => now()])->save();
    }

    public function publishQuestion(User $operator, ProductQuestion $question): void
    {
        $this->requireOperator($operator);
        $question->forceFill(['status' => ProductQuestion::PUBLISHED, 'held_reason' => null, 'moderated_by' => $operator->id, 'moderated_at' => now()])->save();
    }

    public function hideAnswer(User $operator, ProductAnswer $answer): void
    {
        $this->requireOperator($operator);
        $answer->forceFill(['status' => 'hidden', 'moderated_by' => $operator->id, 'moderated_at' => now()])->save();
        $answer->question->forceFill(['answers_count' => $answer->question->answers()->published()->count()])->save();
    }

    /**
     * What the product page shows: published questions with their published answers, newest first, plus the viewer's
     * own held questions (marked) so they know it was received.
     *
     * @return Collection<int, ProductQuestion>
     */
    public function forProduct(Product $product, ?User $viewer = null, int $limit = 20): Collection
    {
        return ProductQuestion::query()->where('product_id', $product->id)
            ->where(function ($q) use ($viewer) {
                $q->where('status', ProductQuestion::PUBLISHED);

                if ($viewer) {
                    $q->orWhere(fn ($own) => $own->where('user_id', $viewer->id)->where('status', ProductQuestion::HELD));
                }
            })
            ->with(['user:id,name', 'answers' => fn ($a) => $a->published()->with('user:id,name')])
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** Questions waiting on a store's people, oldest first. @return Collection<int, ProductQuestion> */
    public function unansweredForStore(int $storeId, int $limit = 50): Collection
    {
        return ProductQuestion::query()->published()->where('answers_count', 0)
            ->whereIn('product_id', Product::withTrashed()->where('store_id', $storeId)->select('id'))
            ->with(['product:id,name,slug', 'user:id,name'])->orderBy('id')->limit($limit)->get();
    }

    private function suspicious(string $body): bool
    {
        return preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|co\.za|net|org|io|me)\b|[\w.+-]+@[\w-]+\.[\w.]+|(?:\+?\d[\s().-]*){9,})~i', $body) === 1;
    }

    private function requireOperator(User $user): void
    {
        if (! $this->members->isOperator($user)) {
            throw ValidationException::withMessages(['moderation' => 'Only the marketplace operator can do that.']);
        }
    }
}
