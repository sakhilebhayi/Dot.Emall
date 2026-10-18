<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * Basic review mechanics. Who is allowed to review (verified purchase only)
 * is covered in VerifiedReviewTest; these tests all use a buyer whose order
 * has been delivered.
 */
class ReviewTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function deliveredBuyerOf($product)
    {
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $order->items->first()->update(['status' => OrderStatus::Delivered]);

        return $buyer;
    }

    public function test_authenticated_user_can_review_a_product(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $buyer = $this->deliveredBuyerOf($product);
        $seller->notifications()->delete();

        $this->actingAs($buyer)
            ->post(route('reviews.store', $product), ['rating' => 5, 'body' => 'Great product!'])
            ->assertRedirect(route('products.show', $product));

        $this->assertDatabaseHas('reviews', [
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'rating' => 5,
        ]);

        // Store owner receives an in-app notification about the new review.
        $this->assertEquals(1, $seller->fresh()->unreadNotifications()->count());
    }

    public function test_a_user_cannot_review_the_same_product_twice(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->deliveredBuyerOf($product);
        Review::create(['product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 4]);

        $this->actingAs($buyer)
            ->post(route('reviews.store', $product), ['rating' => 2])
            ->assertSessionHasErrors('rating');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->deliveredBuyerOf($product);

        foreach ([0, 6, 9, 'x'] as $bad) {
            $this->actingAs($buyer)
                ->post(route('reviews.store', $product), ['rating' => $bad])
                ->assertSessionHasErrors('rating');
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_only_the_review_author_can_delete_it(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $author = $this->deliveredBuyerOf($product);
        $intruder = $this->makeBuyer();
        $review = Review::create(['product_id' => $product->id, 'user_id' => $author->id, 'rating' => 3]);

        $this->actingAs($intruder)
            ->delete(route('reviews.destroy', $review))
            ->assertForbidden();

        $this->assertDatabaseHas('reviews', ['id' => $review->id]);

        $this->actingAs($author)
            ->delete(route('reviews.destroy', $review))
            ->assertRedirect();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }
}
