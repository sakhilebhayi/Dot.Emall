<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added (platform-loop pass, 2026-08-01) alongside the new
 * `POST /products/{product}/reviews` and `DELETE /reviews/{review}` routes
 * — the Review model already existed but nothing could ever create one.
 */
class ReviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Review $review): bool
    {
        return true;
    }

    /**
     * Any authenticated buyer may leave a review; the one-review-per-
     * (product, user) rule is enforced by the existing DB unique
     * constraint on `reviews`, surfaced as a validation error.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the review's own author may delete it.
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }
}
