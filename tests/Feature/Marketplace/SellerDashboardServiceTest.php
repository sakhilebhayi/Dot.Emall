<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\PromoCode;
use App\Models\Review;
use App\Services\SellerDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class SellerDashboardServiceTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function summary($seller): array
    {
        return app(SellerDashboard::class)->summary($seller);
    }

    private function deliver($order, $seller): void
    {
        $order->items->where('seller_id', $seller->id)->each(fn ($l) => $l->update(['status' => OrderStatus::Delivered]));
    }

    public function test_a_mixed_order_credits_each_seller_only_for_their_own_delivered_lines(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 100]), 1],
            [$this->makeListing($b, ['price' => 900]), 1],
        ]);

        $this->deliver($order, $a);
        $this->deliver($order, $b);

        $this->assertSame(10000, $this->summary($a)['revenueCents']);
        $this->assertSame(90000, $this->summary($b)['revenueCents']);
    }

    public function test_an_undelivered_line_earns_nothing_even_if_the_other_sellers_line_was_delivered(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 100]), 1],
            [$this->makeListing($b, ['price' => 900]), 1],
        ]);

        $this->deliver($order, $b);

        $this->assertSame(0, $this->summary($a)['revenueCents']);
        $this->assertSame(90000, $this->summary($b)['revenueCents']);
    }

    public function test_cancelled_lines_never_count_as_revenue_or_as_orders(): void
    {
        $a = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 100]), 1],
            [$this->makeListing($a, ['price' => 50]), 1],
        ]);
        $order->items[0]->update(['status' => OrderStatus::Delivered]);
        $order->items[1]->update(['status' => OrderStatus::Cancelled]);

        $summary = $this->summary($a);

        $this->assertSame(10000, $summary['revenueCents']);
        $this->assertSame(1, $summary['totalOrders']);
    }

    public function test_revenue_is_net_of_the_promo_share_attributed_to_the_sellers_lines(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'TWENTY', 'discount_value' => 20]);
        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 100]), 1],
            [$this->makeListing($b, ['price' => 100]), 1],
        ], ['promo_code' => 'TWENTY']);

        $this->deliver($order, $a);
        $this->deliver($order, $b);

        $this->assertSame(8000, $this->summary($a)['revenueCents'], 'A carries the whole 20 discount');
        $this->assertSame(10000, $this->summary($b)['revenueCents'], 'B is untouched by A\'s promo');
    }

    public function test_orders_awaiting_fulfilment_counts_only_this_sellers_open_lines(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $first = $this->placeOrder($buyer, [[$this->makeListing($a), 1], [$this->makeListing($b), 1]]);
        $this->placeOrder($buyer, [[$this->makeListing($b), 1]]);

        $this->assertSame(1, $this->summary($a)['awaitingFulfilment']);
        $this->assertSame(2, $this->summary($b)['awaitingFulfilment']);

        $first->items->where('seller_id', $a->id)->each(fn ($l) => $l->update(['status' => OrderStatus::Shipped]));
        $this->assertSame(0, $this->summary($a)['awaitingFulfilment']);
    }

    public function test_deleting_a_listing_does_not_remove_its_sales_from_the_numbers(): void
    {
        $a = $this->makeSeller();
        $product = $this->makeListing($a, ['price' => 70]);
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);
        $this->deliver($order, $a);

        $product->delete();

        $this->assertSame(7000, $this->summary($a)['revenueCents']);
        $this->assertSame(1, $this->summary($a)['totalOrders']);
    }

    public function test_recent_orders_carry_only_the_sellers_slice_and_hide_the_buyer_when_nothing_ships(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 10]), 1],
            [$this->makeListing($b, ['price' => 5000]), 1],
        ]);

        $row = $this->summary($a)['recentOrders']->first();

        $this->assertSame(1000, $row['netCents'], 'B\'s 5000 line is not in A\'s row');
        $this->assertSame(1, $row['lineCount']);
        $this->assertSame('Thandiwe Mokoena', $row['buyerName']);

        $this->deliver($order, $a);
        $this->assertNull($this->summary($a)['recentOrders']->first()['buyerName']);
    }

    public function test_the_dashboard_page_never_shows_another_sellers_order_value_or_buyer_address(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 10]), 1],
            [$this->makeListing($b, ['price' => 7654.32]), 1],
        ]);

        $page = $this->actingAs($a)->get(route('dashboard'))->assertOk();

        $page->assertDontSee('7,654.32');
        $page->assertDontSee('7,664.32');
        $page->assertDontSee('14 Protea Lane');
        $page->assertSee('1 order waiting on you to fulfil.');
    }

    public function test_review_and_stock_figures_cover_only_the_sellers_products(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $mine = $this->makeListing($a, ['stock' => 2]);
        $theirs = $this->makeListing($b, ['stock' => 1]);
        Review::factory()->create(['product_id' => $mine->id, 'rating' => 4]);
        Review::factory()->create(['product_id' => $theirs->id, 'rating' => 1]);

        $summary = $this->summary($a);

        $this->assertSame(1, $summary['totalReviews']);
        $this->assertSame(4.0, $summary['avgRating']);
        $this->assertSame(1, $summary['lowStockCount']);
        $this->assertSame(1, $summary['totalProducts']);
    }

    public function test_a_user_with_no_store_gets_an_empty_summary(): void
    {
        $summary = $this->summary($this->makeBuyer());

        $this->assertCount(0, $summary['stores']);
        $this->assertSame(0, $summary['revenueCents']);
        $this->assertSame(0, $summary['totalOrders']);
    }
}
