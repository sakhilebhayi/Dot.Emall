<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Community\StoreMembers;
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

    /** The author edits their own review inside the edit window. */
    public function update(User $user, Review $review): bool
    {
        return $user->id === $review->user_id && app(ReviewService::class)->canEdit($review);
    }

    /** The store that sold the product replies, once. */
    public function respond(User $user, Review $review): bool
    {
        $review->loadMissing('product.store');

        return $review->status === Review::PUBLISHED && app(StoreMembers::class)->canSpeakFor($user, $review->product->store);
    }

    /** Helpful votes: anyone signed in but the author, on a published review. */
    public function vote(User $user, Review $review): bool
    {
        return $user->id !== $review->user_id && $review->status === Review::PUBLISHED;
    }

    public function report(User $user, Review $review): bool
    {
        return $user->id !== $review->user_id && $review->status === Review::PUBLISHED;
    }

    /** Releasing or removing a held or reported review: the operator. */
    public function moderate(User $user, Review $review): bool
    {
        return app(StoreMembers::class)->isOperator($user);
    }

    /**
     * Only the review's own author may delete it.
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }
}
