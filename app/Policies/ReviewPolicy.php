<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Auth\Access\HandlesAuthorization;

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
     * Verified purchase only: the user has a delivered order line for this
     * product, and is not the seller of it. (The one-review-per-product rule
     * is a validation error, not a permission: see ReviewService::submit().)
     */
    public function create(User $user, Product $product): bool
    {
        if ($product->store->owner_id === $user->id) {
            return false;
        }

        return app(ReviewService::class)->hasPurchased($user, $product);
    }

    /**
     * Only the review's own author may delete it.
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }
}
