<?php

namespace App\Services\Community;

use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The seller's reply to a review: one per review, written by the store's own people, visible beside the review.
 * It cannot be edited into something else or deleted by the seller (a seller must not be able to rewrite what the
 * public already read); only the operator can hide it.
 */
class ReviewResponses
{
    public function __construct(private readonly StoreMembers $members) {}

    /**
     * @throws ValidationException
     */
    public function respond(User $user, Review $review, string $body): ReviewResponse
    {
        $review->loadMissing('product.store');
        $store = $review->product->store;
        $body = trim($body);

        if (! $this->members->canSpeakFor($user, $store)) {
            throw ValidationException::withMessages(['body' => 'Only the store that sold this product can reply.']);
        }

        if ($review->status !== Review::PUBLISHED) {
            throw ValidationException::withMessages(['body' => 'This review is not available.']);
        }

        if ($body === '' || mb_strlen($body) > 1000) {
            throw ValidationException::withMessages(['body' => 'Write a reply of up to 1000 characters.']);
        }

        try {
            return DB::transaction(fn () => ReviewResponse::query()->create(['review_id' => $review->id, 'user_id' => $user->id, 'store_id' => $store->id, 'body' => $body]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['body' => 'This review already has a reply from the store.']);
        }
    }

    public function hide(User $operator, ReviewResponse $response): void
    {
        if (! $this->members->isOperator($operator)) {
            throw ValidationException::withMessages(['moderation' => 'Only the marketplace operator can do that.']);
        }

        $response->forceFill(['status' => 'hidden'])->save();
    }
}
