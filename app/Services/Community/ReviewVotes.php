<?php

namespace App\Services\Community;

use App\Models\Review;
use App\Models\ReviewVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** "Was this review helpful?" One vote per person per review, never on your own review. */
class ReviewVotes
{
    /**
     * Adds the person's vote, or takes it back if they already voted. Returns whether they now have a vote on it.
     *
     * @throws ValidationException
     */
    public function toggle(User $user, Review $review): bool
    {
        if ($review->user_id === $user->id) {
            throw ValidationException::withMessages(['vote' => 'You cannot vote on your own review.']);
        }

        if ($review->status !== Review::PUBLISHED) {
            throw ValidationException::withMessages(['vote' => 'This review is not available.']);
        }

        $voted = DB::transaction(function () use ($user, $review) {
            $existing = ReviewVote::query()->where('review_id', $review->id)->where('user_id', $user->id)->first();

            if ($existing) {
                $existing->delete();
                $voted = false;
            } else {
                // insert-or-ignore: a refused insert would poison a PostgreSQL transaction; two clicks at once vote once.
                DB::table('review_votes')->insertOrIgnore(['review_id' => $review->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

                $voted = true;
            }

            $review->forceFill(['helpful_count' => ReviewVote::query()->where('review_id', $review->id)->count()])->save();

            return $voted;
        });

        return $voted;
    }

    /** Review ids among $reviewIds the person has voted on. @param array<int, int> $reviewIds @return array<int, int> */
    public function votedBy(User $user, array $reviewIds): array
    {
        return ReviewVote::query()->where('user_id', $user->id)->whereIn('review_id', $reviewIds)->pluck('review_id')->map(fn ($id) => (int) $id)->all();
    }
}
