<?php

namespace App\Services\Community;

use App\Models\ContentReport;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator-facing data for reviews, questions and answers: what is held, what has been reported, and the actions that
 * resolve them. Every action records who did it and when. Operator only.
 */
class ModerationQueue
{
    public function __construct(
        private readonly StoreMembers $members,
        private readonly ReviewService $reviews,
        private readonly ProductQuestions $questions,
    ) {}

    /**
     * Open reports grouped by what was reported, most reported first.
     *
     * @return Collection<int, array{type: string, id: int, reports: int, reasons: list<string>, first_at: string, excerpt: string|null}>
     */
    public function reported(int $limit = 50): Collection
    {
        $groups = ContentReport::query()->where('status', 'open')
            ->groupBy('reportable_type', 'reportable_id')
            ->selectRaw('reportable_type, reportable_id, count(*) as reports, min(created_at) as first_at')
            ->orderByDesc('reports')->orderBy('first_at')->limit($limit)->get();

        return $groups->map(function ($g) {
            $reasons = ContentReport::query()->where('status', 'open')->where('reportable_type', $g->reportable_type)->where('reportable_id', $g->reportable_id)->distinct()->pluck('reason')->all();

            return [
                'type' => $g->reportable_type,
                'id' => (int) $g->reportable_id,
                'reports' => (int) $g->reports,
                'reasons' => $reasons,
                'first_at' => (string) $g->first_at,
                'excerpt' => $this->excerpt($g->reportable_type, (int) $g->reportable_id),
            ];
        });
    }

    /** @return Collection<int, Review> */
    public function heldReviews(int $limit = 50): Collection
    {
        return Review::query()->where('status', Review::HELD)->with(['user:id,name', 'product:id,name'])->orderBy('id')->limit($limit)->get();
    }

    /** @return Collection<int, ProductQuestion> */
    public function heldQuestions(int $limit = 50): Collection
    {
        return ProductQuestion::query()->where('status', ProductQuestion::HELD)->with(['user:id,name', 'product:id,name'])->orderBy('id')->limit($limit)->get();
    }

    /** @return array{reported: int, held_reviews: int, held_questions: int} */
    public function counts(): array
    {
        return [
            'reported' => (int) ContentReport::query()->where('status', 'open')->distinct()->count(DB::raw("reportable_type || ':' || reportable_id")),
            'held_reviews' => Review::query()->where('status', Review::HELD)->count(),
            'held_questions' => ProductQuestion::query()->where('status', ProductQuestion::HELD)->count(),
        ];
    }

    /**
     * Resolve all open reports on an item. `remove` takes the item down; `dismiss` leaves it up.
     *
     * @throws ValidationException
     */
    public function resolve(User $operator, string $type, int $id, string $action, ?string $note = null): int
    {
        $this->require($operator);

        if (! in_array($action, ['remove', 'dismiss'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose remove or dismiss.']);
        }

        if ($action === 'remove') {
            match ($type) {
                'review' => $this->reviews->moderate($operator, Review::query()->findOrFail($id), Review::REMOVED),
                'question' => $this->questions->hideQuestion($operator, ProductQuestion::query()->findOrFail($id)),
                'answer' => $this->questions->hideAnswer($operator, ProductAnswer::query()->findOrFail($id)),
                default => throw ValidationException::withMessages(['type' => 'Unknown item.']),
            };
        }

        return ContentReport::query()->where('status', 'open')->where('reportable_type', $type)->where('reportable_id', $id)->update([
            'status' => $action === 'remove' ? 'actioned' : 'dismissed',
            'handled_by' => $operator->id,
            'handled_at' => now(),
            'resolution' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    /** Releases a held review or question onto the page. */
    public function approve(User $operator, string $type, int $id): void
    {
        $this->require($operator);

        match ($type) {
            'review' => $this->reviews->moderate($operator, Review::query()->findOrFail($id), Review::PUBLISHED),
            'question' => $this->questions->publishQuestion($operator, ProductQuestion::query()->findOrFail($id)),
            default => throw ValidationException::withMessages(['type' => 'Only reviews and questions are held.']),
        };
    }

    private function excerpt(string $type, int $id): ?string
    {
        $body = match ($type) {
            'review' => Review::query()->whereKey($id)->value('body'),
            'question' => ProductQuestion::query()->whereKey($id)->value('body'),
            'answer' => ProductAnswer::query()->whereKey($id)->value('body'),
            default => null,
        };

        return $body !== null ? mb_substr($body, 0, 200) : null;
    }

    private function require(User $user): void
    {
        if (! $this->members->isOperator($user)) {
            throw ValidationException::withMessages(['moderation' => 'Only the marketplace operator can do that.']);
        }
    }
}
