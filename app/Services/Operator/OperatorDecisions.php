<?php

namespace App\Services\Operator;

use App\Enums\RefundStatus;
use App\Models\ContentReport;
use App\Models\ListingFlagDismissal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OperatorDecisionNotification;
use App\Services\Community\ProductQuestions;
use App\Services\RefundService;
use App\Services\Returns\ReturnService;
use App\Services\ReviewService;
use App\Services\Stores\StoreOnboarding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every decision an operator makes about another party goes through here, so each one has the same three properties:
 *
 *   1. it is refused unless the actor is a platform operator;
 *   2. where it affects someone else it needs a written reason (at least five characters), which the affected person is shown;
 *   3. the change and its audit entry (who, what, about whom, when, why) are saved in ONE transaction: no decision without a record.
 *
 * The domain rules themselves stay in the services that own them (StoreOnboarding, ReviewService, ReturnService, RefundService); this
 * class adds the reason, the record and the notice.
 */
class OperatorDecisions
{
    public const MIN_REASON = 5;

    public function __construct(
        private readonly OperatorAudit $audit,
        private readonly StoreOnboarding $stores,
        private readonly ReviewService $reviews,
        private readonly ProductQuestions $questions,
        private readonly ReturnService $returns,
        private readonly RefundService $refunds,
    ) {}

    // ---- listings -------------------------------------------------------------------------------------------------

    public function approveListing(Product $product, User $operator): Product
    {
        $this->operator($operator);

        DB::transaction(function () use ($product, $operator) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => 'Only a pending listing can be approved.']);
            }

            $locked->update(['status' => 'approved', 'rejected_reason' => null, 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);
            $this->audit->record($operator, 'listing.approve', $locked);
        });

        $product->refresh();
        $this->tell($product->store?->owner, 'listing_approved', 'Your listing is live', "\"{$product->name}\" was approved and shoppers can now find it.", route('products.show', $product), 'See the listing');

        return $product;
    }

    /** Rejects a pending listing, or takes a live one down. The reason is shown to the seller on the listing and in a notice. */
    public function rejectListing(Product $product, User $operator, ?string $reason): Product
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Tell the seller what to fix, in a sentence they can act on.');
        $action = 'listing.reject';

        DB::transaction(function () use ($product, $operator, $reason, &$action) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! in_array($locked->status, ['pending_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only a pending or live listing can be rejected.']);
            }

            $action = $locked->status === 'approved' ? 'listing.takedown' : 'listing.reject';
            $locked->update(['status' => 'rejected', 'rejected_reason' => $reason, 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);
            $this->audit->record($operator, $action, $locked, $reason);
        });

        $product->refresh();
        $this->tell($product->store?->owner, 'listing_rejected', $action === 'listing.takedown' ? 'A listing was taken down' : 'A listing was rejected', "\"{$product->name}\": {$reason}", route('seller.products.edit', $product), 'Open the listing');

        return $product;
    }

    /** The operator looked at a listing the rules flagged and judged it fine. The flag for that rule stays dismissed. */
    public function dismissFlag(Product $product, string $rule, User $operator, ?string $note): void
    {
        $this->operator($operator);
        $note = $this->reason($note, 'Write why the listing is fine, so the next person does not look again.');

        DB::transaction(function () use ($product, $rule, $operator, $note) {
            ListingFlagDismissal::query()->updateOrCreate(['product_id' => $product->id, 'rule' => $rule], ['actor_id' => $operator->id, 'note' => mb_substr($note, 0, 500), 'created_at' => now()]);
            $this->audit->record($operator, 'listing.flag_dismiss', $product, $note, ['rule' => $rule]);
        });
    }

    // ---- stores ---------------------------------------------------------------------------------------------------

    public function approveStore(Store $store, User $operator): Store
    {
        $this->operator($operator);

        $store = DB::transaction(function () use ($store, $operator) {
            $fresh = $this->stores->approve($store, $operator);
            $this->audit->record($operator, 'store.approve', $fresh);

            return $fresh;
        });

        $this->tell($store->owner, 'store_approved', 'Your store was approved', "{$store->name} is open to shoppers.", route('seller.store.edit'), 'Open your store');

        return $store;
    }

    public function sendStoreBack(Store $store, User $operator, ?string $reason): Store
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Write what the seller must change, so they can fix it and submit again.');

        $store = DB::transaction(function () use ($store, $operator, $reason) {
            $fresh = $this->stores->reject($store, $operator, $reason);
            $this->audit->record($operator, 'store.reject', $fresh, $reason);

            return $fresh;
        });

        $this->tell($store->owner, 'store_rejected', 'Your store needs changes', "{$store->name}: {$reason}", route('seller.store.edit'), 'Edit your store');

        return $store;
    }

    public function suspendStore(Store $store, User $operator, ?string $reason): Store
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Write why the store is suspended. The seller is shown this.');

        $store = DB::transaction(function () use ($store, $operator, $reason) {
            $fresh = $this->stores->suspend($store, $operator, $reason);
            $this->audit->record($operator, 'store.suspend', $fresh, $reason);

            return $fresh;
        });

        $this->tell($store->owner, 'store_suspended', 'Your store was suspended', "{$store->name} is closed to shoppers. Reason: {$reason}", route('seller.store.edit'), 'See your store');

        return $store;
    }

    public function reinstateStore(Store $store, User $operator, ?string $note = null): Store
    {
        $this->operator($operator);

        $store = DB::transaction(function () use ($store, $operator, $note) {
            $fresh = $this->stores->reinstate($store, $operator);
            $this->audit->record($operator, 'store.reinstate', $fresh, $note);

            return $fresh;
        });

        $this->tell($store->owner, 'store_reinstated', 'Your store is open again', "{$store->name} is open to shoppers again.", route('seller.store.edit'), 'Open your store');

        return $store;
    }

    // ---- reviews, questions, answers ------------------------------------------------------------------------------

    public function releaseReview(Review $review, User $operator, ?string $note = null): Review
    {
        $this->operator($operator);

        return DB::transaction(function () use ($review, $operator, $note) {
            $review = $this->reviews->moderate($operator, $review, Review::PUBLISHED);
            $this->closeReports('review', $review->id, 'dismissed', $operator, $note ?: 'Released after a check.');
            $this->audit->record($operator, 'review.release', $review, $note);

            return $review;
        });
    }

    public function removeReview(Review $review, User $operator, ?string $reason): Review
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Write why the review is removed. The reviewer is told.');

        $review = DB::transaction(function () use ($review, $operator, $reason) {
            $review = $this->reviews->moderate($operator, $review, Review::REMOVED);
            $this->closeReports('review', $review->id, 'actioned', $operator, $reason);
            $this->audit->record($operator, 'review.remove', $review, $reason);

            return $review;
        });

        $this->tell($review->user, 'review_removed', 'Your review was removed', "Your review of \"{$review->product?->name}\" was removed. Reason: {$reason}", null);

        return $review;
    }

    public function releaseQuestion(ProductQuestion $question, User $operator, ?string $note = null): void
    {
        $this->operator($operator);

        DB::transaction(function () use ($question, $operator, $note) {
            $this->questions->publishQuestion($operator, $question);
            $this->closeReports('question', $question->id, 'dismissed', $operator, $note ?: 'Released after a check.');
            $this->audit->record($operator, 'question.release', $question, $note);
        });
    }

    public function removeQuestion(ProductQuestion $question, User $operator, ?string $reason): void
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Write why the question is removed. The person who asked is told.');

        DB::transaction(function () use ($question, $operator, $reason) {
            $this->questions->hideQuestion($operator, $question);
            $this->closeReports('question', $question->id, 'actioned', $operator, $reason);
            $this->audit->record($operator, 'question.remove', $question, $reason);
        });

        $this->tell($question->user, 'question_removed', 'Your question was removed', "Your question about \"{$question->product?->name}\" was removed. Reason: {$reason}", null);
    }

    public function removeAnswer(ProductAnswer $answer, User $operator, ?string $reason): void
    {
        $this->operator($operator);
        $reason = $this->reason($reason, 'Write why the answer is removed. Its author is told.');

        DB::transaction(function () use ($answer, $operator, $reason) {
            $this->questions->hideAnswer($operator, $answer);
            $this->closeReports('answer', $answer->id, 'actioned', $operator, $reason);
            $this->audit->record($operator, 'answer.remove', $answer, $reason);
        });

        $this->tell($answer->user, 'answer_removed', 'Your answer was removed', "Your answer was removed. Reason: {$reason}", null);
    }

    // ---- reports --------------------------------------------------------------------------------------------------

    /**
     * Settles every open report on one item. `remove` takes the item down (a review or question or answer is hidden, a listing
     * is rejected, a store is suspended); `dismiss` leaves it up. Both need an outcome note: it is what the next operator reads
     * when the same item or seller is reported again.
     *
     * @return int how many reports were closed
     */
    public function resolveReport(User $operator, string $type, int $id, string $action, ?string $note): int
    {
        $this->operator($operator);

        if (! in_array($action, ['remove', 'dismiss'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose remove or dismiss.']);
        }

        if (! in_array($type, ContentReport::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Unknown item.']);
        }

        $note = $this->reason($note, 'Write the outcome in a sentence: it is kept with the item for the next person who looks.');
        $open = ContentReport::query()->where('status', 'open')->where('reportable_type', $type)->where('reportable_id', $id)->count();

        if ($open === 0) {
            throw ValidationException::withMessages(['report' => 'Nobody has an open report on that now.']);
        }

        if ($action === 'dismiss') {
            return DB::transaction(function () use ($operator, $type, $id, $note) {
                $closed = $this->closeReports($type, $id, 'dismissed', $operator, $note);
                $this->audit->record($operator, 'report.dismiss', ['type' => $type, 'id' => $id, 'label' => ucfirst($type).' #'.$id], $note, ['reports' => $closed]);

                return $closed;
            });
        }

        // Taking something down: the specific decision runs its own rules, closes the reports and writes the audit line itself.
        return match ($type) {
            'review' => $this->removeAndCount(fn () => $this->removeReview(Review::query()->with('product', 'user')->findOrFail($id), $operator, $note), $open),
            'question' => $this->removeAndCount(fn () => $this->removeQuestion(ProductQuestion::query()->with('product', 'user')->findOrFail($id), $operator, $note), $open),
            'answer' => $this->removeAndCount(fn () => $this->removeAnswer(ProductAnswer::query()->with('user')->findOrFail($id), $operator, $note), $open),
            'product' => $this->takeDownReported(Product::query()->findOrFail($id), $operator, $note, $open),
            'store' => $this->suspendReported(Store::query()->findOrFail($id), $operator, $note, $open),
        };
    }

    // ---- returns --------------------------------------------------------------------------------------------------

    /**
     * Settles an escalated return. `approve` agrees to the return (the refund follows when the seller marks the goods received);
     * `approve_refund` agrees and refunds now (the goods are back, or the case does not need them); `uphold_decline` closes it.
     * Both sides read the note.
     */
    public function settleReturn(ReturnRequest $return, User $operator, string $decision, ?string $note): ReturnRequest
    {
        $this->operator($operator);
        $note = $this->reason($note, 'Write why, so the buyer and the seller can both read it.');

        if (! in_array($decision, ['approve', 'approve_refund', 'uphold_decline'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose a decision.']);
        }

        $settled = DB::transaction(function () use ($return, $operator, $decision, $note) {
            $settled = $this->returns->operatorResolve($return, $operator, $decision === 'uphold_decline' ? 'uphold_decline' : 'approve', $note);

            if ($decision === 'approve_refund') {
                $settled = $this->returns->markReceived($settled, $operator, false);
            }

            $this->audit->record($operator, 'return.'.$decision, $settled, $note, ['order_id' => $settled->order_id, 'status' => $settled->status->value]);

            return $settled;
        });

        $this->tell($settled->seller, 'return_settled', 'A return was settled', 'The marketplace team settled return #'.$settled->id.'. '.$note, null);

        return $settled->refresh();
    }

    // ---- money ----------------------------------------------------------------------------------------------------

    public function reviewPayment(Payment $payment, User $operator, ?string $note): Payment
    {
        $this->operator($operator);
        $note = $this->reason($note, 'Write what you checked and what you decided.');

        DB::transaction(function () use ($payment, $operator, $note) {
            $payment->forceFill(['reviewed_at' => now(), 'reviewed_by' => $operator->id, 'review_note' => $note])->save();
            $this->audit->record($operator, 'payment.review', $payment, $note, ['order_id' => $payment->order_id, 'amount_cents' => $payment->amount_cents]);
        });

        return $payment;
    }

    public function retryRefund(Refund $refund, User $operator): Refund
    {
        $this->operator($operator);

        if ($refund->status === RefundStatus::Failed) {
            $refund->forceFill(['status' => RefundStatus::Pending, 'provider_refund_id' => null])->save();
        }

        $refund = $this->refunds->submit($refund);
        $this->audit->record($operator, 'refund.retry', ['type' => 'refund', 'id' => $refund->id, 'label' => 'Refund #'.$refund->id], null, ['order_id' => $refund->order_id, 'result' => $refund->status->value, 'amount_cents' => $refund->amount_cents]);

        return $refund;
    }

    public function markRefundDone(Refund $refund, User $operator, ?string $note): void
    {
        $this->operator($operator);
        $note = $this->reason($note, 'Write where you refunded it and the reference, so the books can be checked.');

        DB::transaction(function () use ($refund, $operator, $note) {
            $this->refunds->markDoneByOperator($refund, $operator, $note);
            $this->audit->record($operator, 'refund.mark_done', ['type' => 'refund', 'id' => $refund->id, 'label' => 'Refund #'.$refund->id], $note, ['order_id' => $refund->order_id, 'amount_cents' => $refund->amount_cents]);
        });
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    private function takeDownReported(Product $product, User $operator, string $note, int $open): int
    {
        if ($product->status === 'rejected') {
            $this->closeReports('product', $product->id, 'actioned', $operator, $note);
        } else {
            DB::transaction(function () use ($product, $operator, $note) {
                $this->rejectListing($product, $operator, $note);
                $this->closeReports('product', $product->id, 'actioned', $operator, $note);
            });
        }

        $this->audit->record($operator, 'report.action', $product, $note, ['reports' => $open, 'result' => 'listing taken down']);

        return $open;
    }

    private function suspendReported(Store $store, User $operator, string $note, int $open): int
    {
        DB::transaction(function () use ($store, $operator, $note) {
            $this->suspendStore($store, $operator, $note);
            $this->closeReports('store', $store->id, 'actioned', $operator, $note);
        });
        $this->audit->record($operator, 'report.action', $store, $note, ['reports' => $open, 'result' => 'store suspended']);

        return $open;
    }

    /** @param  callable(): mixed  $remove */
    private function removeAndCount(callable $remove, int $open): int
    {
        $remove();

        return $open;
    }

    private function closeReports(string $type, int $id, string $status, User $operator, ?string $note): int
    {
        return ContentReport::query()->where('status', 'open')->where('reportable_type', $type)->where('reportable_id', $id)->update([
            'status' => $status,
            'handled_by' => $operator->id,
            'handled_at' => now(),
            'resolution' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
        ]);
    }

    private function operator(User $user): void
    {
        if (! $user->is_platform_operator) {
            throw new AuthorizationException('Only the Emall team can do this.');
        }
    }

    private function reason(?string $reason, string $message): string
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return mb_substr($reason, 0, 1000);
    }

    private function tell(?User $person, string $kind, string $title, string $message, ?string $url, ?string $action = null): void
    {
        if ($person === null) {
            return;
        }

        try {
            $person->notify(new OperatorDecisionNotification($kind, $title, $message, $url, $action));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
