<?php

namespace App\Services\Community;

use App\Models\Review;
use App\Models\User;
use App\Services\Search\Text;

/**
 * The review anti-manipulation checks. There is deliberately no IP, device or account-age signal (none is recorded);
 * only two things that can be measured from reviews themselves:
 *
 *   too_many_today   a person posting more than N reviews (default 3) in 24 hours
 *   duplicate_text   the same text, or nearly the same (similarity above 0.9), already posted by the same person on
 *                    another product, or word for word by someone else when it is long enough (40+ characters) to
 *                    be unlikely by chance
 *
 * A match HOLDS the review for the operator; it is not rejected, and the author is told it is waiting. The operator
 * can release it.
 */
class ReviewIntegrity
{
    /** @return string|null why the review should be held, or null to publish */
    public function assess(User $user, ?string $body, ?int $ignoring = null): ?string
    {
        $max = (int) config('discovery.reviews.max_reviews_per_day', 3);

        $today = Review::query()->where('user_id', $user->id)->where('created_at', '>', now()->subDay())
            ->when($ignoring, fn ($q) => $q->where('id', '!=', $ignoring))->count();

        if ($today >= $max) {
            return 'too_many_today';
        }

        $body = trim((string) $body);

        if (mb_strlen($body) < (int) config('discovery.reviews.min_body_length_for_duplicate_check', 20)) {
            return null;
        }

        $hash = $this->hash($body);

        $same = Review::query()->where('body_hash', $hash)->when($ignoring, fn ($q) => $q->where('id', '!=', $ignoring));

        if ((clone $same)->where('user_id', $user->id)->exists() || (mb_strlen($body) >= 40 && (clone $same)->exists())) {
            return 'duplicate_text';
        }

        $threshold = (float) config('discovery.reviews.duplicate_similarity', 0.9);
        $normalised = Text::normalise($body);

        $others = Review::query()->where('user_id', $user->id)->whereNotNull('body')
            ->when($ignoring, fn ($q) => $q->where('id', '!=', $ignoring))
            ->orderByDesc('id')->limit(20)->pluck('body');

        foreach ($others as $other) {
            similar_text($normalised, Text::normalise((string) $other), $percent);

            if ($percent / 100 >= $threshold) {
                return 'duplicate_text';
            }
        }

        return null;
    }

    public function hash(?string $body): ?string
    {
        $text = Text::normalise((string) $body);

        return $text === '' ? null : sha1($text);
    }
}
