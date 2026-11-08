<?php

namespace Tests\Feature\Integration;

use App\Enums\OrderStatus;
use App\Models\Review;
use App\Models\User;
use App\Services\Community\StoreRatings;
use App\Services\Discovery\CountedOrders;
use App\Services\Returns\ReturnService;
use App\Services\ReviewService;
use App\Services\Stores\StoreTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Where data A (commerce) and data B (discovery) both had an answer, there is now one (D-51): who is a verified
 * reviewer comes from A's state machine, and a store's rating on the trust ladder is B's aggregate.
 */
class ReconciliationTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    public function test_a_collected_line_makes_a_verified_purchase_just_like_a_delivered_one(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['type' => 'collection', 'price' => 0, 'collection_address' => '1 Depot Road']);
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($seller, ['price' => 120]);
        $order = $this->placeOrder($buyer, [[$product, 1]]);

        $this->assertNull(app(ReviewService::class)->deliveredItemFor($buyer, $product), 'not collected yet');

        $this->advanceAll($order, OrderStatus::ReadyForCollection, OrderStatus::Collected);
        $review = app(ReviewService::class)->submit($buyer, $product, 5, 'Collected it on Saturday, exactly as described.');

        $this->assertTrue($review->verified_purchase);
        $this->assertSame($this->lineOf($order)->id, $review->order_item_id);
    }

    public function test_a_delivered_line_that_was_then_refunded_no_longer_verifies_a_review(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($seller, ['price' => 120]);
        $order = $this->deliver($this->placeOrder($buyer, [[$product, 1]]));
        $line = $this->lineOf($order);

        $this->assertSame($line->id, app(ReviewService::class)->deliveredItemFor($buyer, $product)?->id);

        $return = app(ReturnService::class)->request($line->fresh(), $buyer, 'faulty', 'Broken on arrival');
        app(ReturnService::class)->approve($return, $seller);
        app(ReturnService::class)->markReceived($return->fresh(), $seller);

        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);
        $this->assertNull(app(ReviewService::class)->deliveredItemFor($buyer, $product));
    }

    public function test_the_trust_ladder_shows_the_store_rating_from_published_reviews_only(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 50]);

        $reviewers = [];

        for ($i = 1; $i <= 5; $i++) {
            $buyer = $this->makeBuyer();
            $this->deliver($this->placeOrder($buyer, [[$product, 1]]));
            $reviewers[] = $buyer;
        }

        foreach ($reviewers as $i => $buyer) {
            app(ReviewService::class)->submit($buyer, $product, $i < 4 ? 5 : 3, 'Review number '.($i + 1).' with its own words about the product.');
        }

        // Five published reviews: 5, 5, 5, 5, 3.
        $metrics = StoreTrust::for($seller->store->fresh())['metrics'];
        $this->assertSame(5, $metrics['review_count']['value']);
        $this->assertEqualsWithDelta(4.6, $metrics['review_average']['value'], 0.001);

        // An operator takes one down: the ladder follows the same aggregate the product pages use.
        $operator = User::factory()->create(['is_platform_operator' => true]);
        app(ReviewService::class)->moderate($operator, Review::query()->where('rating', 3)->firstOrFail(), Review::REMOVED);

        $metrics = StoreTrust::for($seller->store->fresh())['metrics'];
        $this->assertSame(4, $metrics['review_count']['value']);
        $this->assertNull($metrics['review_average']['value'], 'below five published reviews no average is shown, here or on the store page');
        $this->assertSame(app(StoreRatings::class)->summary($seller->store)['average'], $metrics['review_average']['value']);
    }

    public function test_counted_sales_follow_the_state_machine_collected_counts_and_a_refunded_return_does_not(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['type' => 'collection', 'price' => 0, 'collection_address' => '1 Depot Road']);
        $collected = $this->makeListing($seller, ['price' => 90, 'stock' => 20]);
        $buyer = $this->makeBuyer();

        $order = $this->placeOrder($buyer, [[$collected, 2]]);
        $this->assertSame([], app(CountedOrders::class)->unitsSoldByProduct(), 'an order that is only placed is not a sale');

        $this->advanceAll($order, OrderStatus::ReadyForCollection, OrderStatus::Collected);
        $this->assertSame([$collected->id => 2], app(CountedOrders::class)->unitsSoldByProduct());

        $seller2 = $this->makeSeller();
        $this->withDelivery($seller2);
        $returned = $this->makeListing($seller2, ['price' => 50, 'stock' => 20]);
        $deliveredOrder = $this->deliver($this->placeOrder($buyer, [[$returned, 1]]));
        $this->assertSame(1, app(CountedOrders::class)->unitsSoldByProduct()[$returned->id]);

        $return = app(ReturnService::class)->request($this->lineOf($deliveredOrder)->fresh(), $buyer, 'faulty');
        app(ReturnService::class)->approve($return, $seller2);
        app(ReturnService::class)->markReceived($return->fresh(), $seller2);

        $this->assertArrayNotHasKey($returned->id, app(CountedOrders::class)->unitsSoldByProduct(), 'the refunded line is not a sale to rank or recommend by');
    }
}
