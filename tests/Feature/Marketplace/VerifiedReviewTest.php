<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\Review;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class VerifiedReviewTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function purchase($buyer, $product, OrderStatus $status)
    {
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $order->items->first()->update(['status' => $status]);

        return $order;
    }

    private function review($buyer, $product, int $rating = 5, ?string $body = 'Solid, arrived well packed.')
    {
        return $this->actingAs($buyer)->post(route('reviews.store', $product), ['rating' => $rating, 'body' => $body]);
    }

    public function test_someone_who_never_bought_the_product_cannot_review_it(): void
    {
        $product = $this->makeListing($this->makeSeller());

        $this->review($this->makeBuyer(), $product)->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    #[DataProvider('notDeliveredStatuses')]
    public function test_an_order_that_is_not_delivered_does_not_entitle_a_review(OrderStatus $status): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $product, $status);

        $this->review($buyer, $product)->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public static function notDeliveredStatuses(): array
    {
        return [[OrderStatus::Pending], [OrderStatus::Processing], [OrderStatus::Shipped], [OrderStatus::Cancelled]];
    }

    public function test_a_buyer_with_a_delivered_line_can_review(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $product, OrderStatus::Delivered);
        $seller->notifications()->delete();

        $this->review($buyer, $product, 4)->assertRedirect(route('products.show', $product));

        $this->assertDatabaseHas('reviews', ['product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 4]);
        $this->assertSame(1, $seller->fresh()->unreadNotifications()->count());
    }

    public function test_someone_elses_delivered_order_does_not_count(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $this->purchase($this->makeBuyer(), $product, OrderStatus::Delivered);

        $this->review($this->makeBuyer(), $product)->assertForbidden();
    }

    public function test_a_delivered_line_for_a_different_product_does_not_count(): void
    {
        $seller = $this->makeSeller();
        $bought = $this->makeListing($seller);
        $other = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $bought, OrderStatus::Delivered);

        $this->review($buyer, $other)->assertForbidden();
    }

    public function test_the_verified_purchase_survives_the_listing_being_archived_but_reviews_then_404(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $product, OrderStatus::Delivered);
        $product->delete();

        $this->review($buyer, $product)->assertNotFound();
    }

    public function test_a_second_review_of_the_same_product_is_refused(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $product, OrderStatus::Delivered);

        $this->review($buyer, $product, 5);
        $this->review($buyer, $product, 1)->assertSessionHasErrors('rating');

        $this->assertSame(1, Review::count());
        $this->assertSame(5, Review::first()->rating);
    }

    public function test_the_database_enforces_one_review_per_product_per_user(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        Review::factory()->create(['product_id' => $product->id, 'user_id' => $buyer->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Review::factory()->create(['product_id' => $product->id, 'user_id' => $buyer->id]);
    }

    public function test_a_seller_cannot_review_their_own_listing_even_with_a_delivered_order(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->purchase($seller, $product, OrderStatus::Delivered);

        $this->review($seller, $product)->assertForbidden();
    }

    public function test_the_average_and_count_are_recomputed_when_reviews_come_and_go(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $this->assertSame(0, $product->fresh()->rating_count);

        $buyers = collect([5, 4, 2])->map(function ($rating) use ($product) {
            $buyer = $this->makeBuyer();
            $this->purchase($buyer, $product, OrderStatus::Delivered);
            $this->review($buyer, $product, $rating);

            return $buyer;
        });

        $product = $product->fresh();
        $this->assertSame(3, $product->rating_count);
        $this->assertEquals(3.67, (float) $product->rating_average);

        $this->actingAs($buyers[2])->delete(route('reviews.destroy', Review::where('user_id', $buyers[2]->id)->first()));

        $product = $product->fresh();
        $this->assertSame(2, $product->rating_count);
        $this->assertEquals(4.5, (float) $product->rating_average);

        foreach ([0, 1] as $i) {
            $this->actingAs($buyers[$i])->delete(route('reviews.destroy', Review::where('user_id', $buyers[$i]->id)->first()));
        }
        $product = $product->fresh();
        $this->assertSame(0, $product->rating_count);
        $this->assertEquals(0, (float) $product->rating_average);
    }

    public function test_the_stored_average_always_matches_the_reviews(): void
    {
        $product = $this->makeListing($this->makeSeller());
        foreach ([1, 2, 3, 4, 5, 5] as $rating) {
            $buyer = $this->makeBuyer();
            $this->purchase($buyer, $product, OrderStatus::Delivered);
            $this->review($buyer, $product, $rating);
        }

        $product = $product->fresh();
        $this->assertEquals(round(Review::where('product_id', $product->id)->avg('rating'), 2), (float) $product->rating_average);
        $this->assertSame(Review::where('product_id', $product->id)->count(), $product->rating_count);
    }

    public function test_the_product_page_offers_the_form_only_to_eligible_buyers(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $stranger = $this->makeBuyer();
        $this->purchase($buyer, $product, OrderStatus::Delivered);

        $this->actingAs($buyer)->get(route('products.show', $product))->assertOk()->assertSee('Leave a review')->assertSee(route('reviews.store', $product), false);

        $page = $this->actingAs($stranger)->get(route('products.show', $product))->assertOk();
        $page->assertDontSee(route('reviews.store', $product), false);
        $page->assertSee('an order containing it has been delivered');
    }

    public function test_the_buyer_who_already_reviewed_sees_no_form_again(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $this->purchase($buyer, $product, OrderStatus::Delivered);
        $this->review($buyer, $product);

        $this->actingAs($buyer)->get(route('products.show', $product))->assertDontSee(route('reviews.store', $product), false);
    }

    public function test_reviewers_are_shown_by_first_name_and_initial_only(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $buyer->update(['name' => 'Nomvula Zanele Dlamini']);
        $this->purchase($buyer, $product, OrderStatus::Delivered);
        $this->review($buyer, $product, 5, 'Beautiful finish and quick delivery all round.');

        $page = $this->actingAs($this->makeBuyer())->get(route('products.show', $product));

        $page->assertSee('Nomvula D.');
        $page->assertDontSee('Zanele');
        $page->assertDontSee('Dlamini');
    }
}
