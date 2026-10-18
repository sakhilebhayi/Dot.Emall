<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviews are verified-purchase only: a user may review a product once they
 * have a DELIVERED, non-cancelled order line for it, and only once. Writing
 * or removing a review recomputes the product's stored average rating and
 * review count inside the same transaction, holding a lock on the product
 * row, so two simultaneous reviews cannot leave the aggregate stale.
 */
class ReviewService
{
    public function hasPurchased(User $user, Product $product): bool
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->where('status', OrderStatus::Delivered->value)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    public function hasReviewed(User $user, Product $product): bool
    {
        return Review::query()->where('product_id', $product->id)->where('user_id', $user->id)->exists();
    }

    /**
     * @throws ValidationException when the user already reviewed this product
     */
    public function submit(User $user, Product $product, int $rating, ?string $body): Review
    {
        try {
            return DB::transaction(function () use ($user, $product, $rating, $body) {
                Product::query()->withTrashed()->lockForUpdate()->findOrFail($product->id);

                if ($this->hasReviewed($user, $product)) {
                    throw ValidationException::withMessages(['rating' => 'You have already reviewed this product.']);
                }

                $review = Review::create([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'rating' => $rating,
                    'body' => $body,
                ]);

                $this->refreshAggregate($product);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // The unique (product_id, user_id) index is the source of truth;
            // this is the same answer as the friendly check above, for the
            // race where two submissions arrive together.
            throw ValidationException::withMessages(['rating' => 'You have already reviewed this product.']);
        }
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

    private function refreshAggregate(Product $product): void
    {
        $stats = Review::query()
            ->where('product_id', $product->id)
            ->selectRaw('COUNT(*) as review_count, AVG(rating) as review_average')
            ->first();

        Product::withTrashed()->where('id', $product->id)->update([
            'rating_count' => (int) $stats->review_count,
            'rating_average' => round((float) $stats->review_average, 2),
        ]);
    }
}
