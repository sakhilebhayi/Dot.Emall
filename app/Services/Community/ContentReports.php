<?php

namespace App\Services\Community;

use App\Models\ContentReport;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Report" on a review, question or answer. A report goes to the operator queue; it never hides anything by itself
 * (a pile of reports must not be a way to silence a seller). One report per person per item; not on your own words.
 */
class ContentReports
{
    /**
     * @throws ValidationException
     */
    public function report(User $reporter, string $type, int $id, string $reason, ?string $details = null): ContentReport
    {
        if (! in_array($type, ContentReport::TYPES, true) || ! in_array($reason, ContentReport::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose what is wrong with it.']);
        }

        $authorId = $this->authorOf($type, $id);

        if ($authorId === null) {
            throw ValidationException::withMessages(['reason' => 'That is not available to report.']);
        }

        if ($authorId === $reporter->id) {
            throw ValidationException::withMessages(['reason' => 'You cannot report your own post.']);
        }

        try {
            // In a transaction of its own (a savepoint when nested): on PostgreSQL a refused insert would otherwise
            // poison the surrounding transaction.
            return DB::transaction(fn () => ContentReport::query()->create([
                'reportable_type' => $type,
                'reportable_id' => $id,
                'reporter_id' => $reporter->id,
                'reason' => $reason,
                'details' => $details !== null ? mb_substr(trim($details), 0, 1000) : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reason' => 'You have already reported this. The operator will look at it.']);
        }
    }

    /** Author of a visible item, or null when it does not exist or is not public. */
    private function authorOf(string $type, int $id): ?int
    {
        return match ($type) {
            'review' => Review::query()->published()->whereKey($id)->value('user_id'),
            'question' => ProductQuestion::query()->published()->whereKey($id)->value('user_id'),
            'answer' => ProductAnswer::query()->published()->whereKey($id)->value('user_id'),
            default => null,
        };
    }
}
