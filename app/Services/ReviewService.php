<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Community\ReviewIntegrity;
use App\Services\Community\ReviewPhotos;
use App\Services\Community\StoreMembers;
use App\Services\Community\StoreRatings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviews are verified-purchase only: a user may review a product once they
 * have a DELIVERED, non-cancelled order line for it, and only once. Writing
 * or removing a review recomputes the product's stored average rating and
 * review count inside the same transaction, holding a lock on the product
 * row, so two simultaneous reviews cannot leave the aggregate stale.
 *
 * The verified flag and the order line it came from are written HERE, from the
 * order lines, never taken from a request. Only PUBLISHED reviews count toward
 * the aggregates and appear to the public: a review the integrity checks hold
 * (too many in a day, copied text) waits for the operator and does not move the
 * rating until released. `emall:reviews-rebuild` recomputes every aggregate from
 * the reviews themselves, so a drift can always be repaired.
 */
class ReviewService
{
    public function __construct(
        private readonly ReviewIntegrity $integrity,
        private readonly ReviewPhotos $photos,
        private readonly StoreRatings $storeRatings,
        private readonly StoreMembers $members,
    ) {}

    public function hasPurchased(User $user, Product $product): bool
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->whereIn('status', OrderStatus::completedValues())
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    /** The delivered order line a new review would be attached to: the oldest not yet used by a review. */
    public function deliveredItemFor(User $user, Product $product): ?OrderItem
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->where('status', OrderStatus::Delivered->value)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotIn('id', Review::query()->whereNotNull('order_item_id')->select('order_item_id'))
            ->orderBy('id')
            ->first();
    }

    public function hasReviewed(User $user, Product $product): bool
    {
        return Review::query()->where('product_id', $product->id)->where('user_id', $user->id)->exists();
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws ValidationException when the user already reviewed this product, or a photo is refused
     */
    public function submit(User $user, Product $product, int $rating, ?string $body, array $photos = []): Review
    {
        $this->photos->validate($photos);

        try {
            $review = DB::transaction(function () use ($user, $product, $rating, $body) {
                Product::query()->withTrashed()->lockForUpdate()->findOrFail($product->id);

                if ($this->hasReviewed($user, $product)) {
                    throw ValidationException::withMessages(['rating' => 'You have already reviewed this product.']);
                }

                $item = $this->deliveredItemFor($user, $product);
                $held = $this->integrity->assess($user, $body);

                $review = new Review(['product_id' => $product->id, 'user_id' => $user->id, 'rating' => $rating, 'body' => $body]);
                $review->forceFill([
                    'verified_purchase' => $item !== null,
                    'order_item_id' => $item?->id,
                    'status' => $held === null ? Review::PUBLISHED : Review::HELD,
                    'held_reason' => $held,
                    'body_hash' => $this->integrity->hash($body),
                ])->save();

                $this->refreshAggregate($product);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // The unique (product_id, user_id) index is the source of truth;
            // this is the same answer as the friendly check above, for the
            // race where two submissions arrive together.
            throw ValidationException::withMessages(['rating' => 'You have already reviewed this product.']);
        }

        if ($photos !== []) {
            $this->photos->attach($review, $photos);
        }

        return $review->refresh();
    }

    /**
     * Changes the author's own review within the edit window. The text is checked again.
     *
     * @throws ValidationException
     */
    public function update(User $user, Review $review, int $rating, ?string $body): Review
    {
        if ($review->user_id !== $user->id) {
            throw ValidationException::withMessages(['rating' => 'You can only edit your own review.']);
        }

        if (! $this->canEdit($review)) {
            throw ValidationException::withMessages(['rating' => 'A review can be edited for '.config('discovery.reviews.edit_window_days', 30).' days after it is posted.']);
        }

        DB::transaction(function () use ($review, $rating, $body) {
            Product::query()->withTrashed()->lockForUpdate()->find($review->product_id);
            $review->refresh();

            $held = $this->integrity->assess(User::query()->findOrFail($review->user_id), $body, ignoring: $review->id);

            $review->forceFill([
                'rating' => $rating,
                'body' => $body,
                'body_hash' => $this->integrity->hash($body),
                'edited_at' => now(),
                // An edit can put a review on hold; it never releases one the operator held or removed.
                'status' => $review->status === Review::PUBLISHED && $held !== null ? Review::HELD : $review->status,
                'held_reason' => $review->status === Review::PUBLISHED && $held !== null ? $held : $review->held_reason,
            ])->save();

            if ($product = Product::withTrashed()->find($review->product_id)) {
                $this->refreshAggregate($product);
            }
        });

        return $review->refresh();
    }

    public function canEdit(Review $review): bool
    {
        return $review->status !== Review::REMOVED
            && $review->created_at !== null
            && $review->created_at->gt(now()->subDays((int) config('discovery.reviews.edit_window_days', 30)));
    }

    /** Operator: release a held review, or take one down. */
    public function moderate(User $operator, Review $review, string $status): Review
    {
        if (! $this->members->isOperator($operator)) {
            throw ValidationException::withMessages(['moderation' => 'Only the marketplace operator can do that.']);
        }

        if (! in_array($status, [Review::PUBLISHED, Review::REMOVED], true)) {
            throw ValidationException::withMessages(['status' => 'A review is released or removed.']);
        }

        DB::transaction(function () use ($review, $status, $operator) {
            Product::query()->withTrashed()->lockForUpdate()->find($review->product_id);

            $review->forceFill([
                'status' => $status,
                'held_reason' => $status === Review::PUBLISHED ? null : $review->held_reason,
                'moderated_by' => $operator->id,
                'moderated_at' => now(),
            ])->save();

            if ($product = Product::withTrashed()->find($review->product_id)) {
                $this->refreshAggregate($product);
            }
        });

        return $review->refresh();
    }

    public function delete(Review $review): void
    {
        DB::transaction(function () use ($review) {
            Product::query()->withTrashed()->lockForUpdate()->find($review->product_id);

            $review->delete();

            if ($product = Product::withTrashed()->find($review->product_id)) {
                $this->refreshAggregate($product);
            }
        });
    }

    /** Recomputes one product's stored rating, and its store's, from PUBLISHED reviews. */
    public function refreshAggregate(Product $product): void
    {
        $stats = Review::query()
            ->where('product_id', $product->id)
            ->published()
            ->selectRaw('COUNT(*) as review_count, AVG(rating) as review_average')
            ->first();

        Product::withTrashed()->where('id', $product->id)->update([
            'rating_count' => (int) $stats->review_count,
            'rating_average' => round((float) $stats->review_average, 2),
        ]);

        $this->storeRatings->refresh((int) $product->store_id);
    }
}
