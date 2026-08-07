<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(?User $sellerOwner = null): Product
    {
        $seller = $sellerOwner ?? User::factory()->create();
        $store = Store::create([
            'owner_id' => $seller->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);

        return Product::create([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
        ]);
    }

    public function test_authenticated_user_can_review_a_product(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($seller);

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
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct();

        Review::create(['product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 4]);

        $this->actingAs($buyer)
            ->post(route('reviews.store', $product), ['rating' => 2])
            ->assertSessionHasErrors('rating');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct();

        $this->actingAs($buyer)
            ->post(route('reviews.store', $product), ['rating' => 9])
            ->assertSessionHasErrors('rating');
    }

    public function test_only_the_review_author_can_delete_it(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct();
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
