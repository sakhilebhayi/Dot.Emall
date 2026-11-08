<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Services\Community\StoreRatings;
use App\Services\Seller\SellerAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/**
 * The analytics definitions, checked on rows we seeded ourselves: what counts, what never counts, what the period includes,
 * "No data yet" instead of zeros dressed as results, and the CSV files.
 */
class SellerAnalyticsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPaidOrders;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function analytics(): SellerAnalytics
    {
        return app(SellerAnalytics::class);
    }

    /** A paid order of [product, quantity] pairs (the paid state is forced; the money path is covered elsewhere). */
    private function paid(array $lines): Order
    {
        $order = $this->placeOrder($this->makeBuyer(), $lines);
        $order->forceFill(['requires_payment' => true, 'payment_status' => 'paid', 'paid_at' => now()])->save();

        return $order->refresh();
    }

    private function event(string $name, Product $product, string $actor, ?string $source = null, ?Carbon $at = null): void
    {
        DB::table('commerce_events')->insert(['name' => $name, 'actor_hash' => $actor, 'day' => ($at ?? now())->toDateString(), 'product_id' => $product->id, 'store_id' => $product->store_id, 'source' => $source, 'created_at' => $at ?? now()]);
    }

    public function test_a_new_store_has_no_data_and_says_so_instead_of_showing_zeros_as_results(): void
    {
        $seller = $this->makeSeller();
        $page = $this->actingAs($seller)->get(route('seller.analytics'))->assertOk();

        $page->assertSee('No data yet');
        $report = $page->viewData('sales');
        $this->assertFalse($report['has_data']);
        $this->assertNull($report['aov_cents'], 'no average without orders');
        $this->assertSame([], $page->viewData('products')->all());
        $page->assertDontSee('R0.00')->assertDontSee('sd-bars', false);
        $this->get(route('seller.dashboard'))->assertSee('No data yet')->assertSee('Nothing needs you right now');
    }

    public function test_sales_orders_units_and_the_average_count_only_paid_uncancelled_lines_after_discount_and_without_delivery(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 50, 'name' => 'Counted Kettle']);
        $mug = $this->makeListing($seller, ['price' => 50, 'stock' => 50, 'name' => 'Counted Mug']);
        $other = $this->makeSeller();
        $theirs = $this->makeListing($other, ['price' => 999, 'stock' => 5]);

        // Paid: 2 kettles + 1 mug = 250; paid with 10% off one kettle = 90.
        $this->paid([[$kettle, 2], [$mug, 1]]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TEN', 'discount_type' => 'percent', 'discount_value' => 10]);
        $discounted = $this->placeOrder($this->makeBuyer(), [[$kettle, 1]], ['promo_code' => 'TEN']);
        $discounted->forceFill(['requires_payment' => true, 'payment_status' => 'paid', 'paid_at' => now()])->save();
        // Never counted: waiting for payment, a cancelled line, another store's order.
        $this->enablePaystack();
        $this->placeOrder($this->makeBuyer(), [[$kettle, 1]]);
        $cancelled = $this->paidOrder($this->makeBuyer(), [$mug]);
        $cancelled->items()->update(['status' => OrderStatus::Cancelled->value]);
        $this->paidOrder($this->makeBuyer(), [$theirs]);

        $w = $this->analytics()->window(30);
        $sales = $this->analytics()->sales($seller->store, $w['from'], $w['to']);

        $this->assertSame(34000, $sales['sales_cents'], '250.00 + 90.00 after the promo, no delivery fee');
        $this->assertSame(2, $sales['orders']);
        $this->assertSame(4, $sales['units']);
        $this->assertSame(17000, $sales['aov_cents']);
        $this->actingAs($seller)->get(route('seller.analytics'))->assertSee('R340.00')->assertSee('R170.00')->assertDontSee('R999.00');
    }

    public function test_the_period_decides_what_is_in_and_the_previous_period_is_the_same_length_before(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 50]);
        Carbon::setTestNow(now()->subDays(40));
        $this->paidOrder($this->makeBuyer(), [$kettle]);
        Carbon::setTestNow();
        $this->paid([[$kettle, 2]]);

        $r30 = $this->analytics()->report($seller->store, 30);
        $r90 = $this->analytics()->report($seller->store, 90);

        $this->assertSame([20000, 1], [$r30['sales']['sales_cents'], $r30['sales']['orders']]);
        $this->assertSame([10000, 1], [$r30['previous']['sales_cents'], $r30['previous']['orders']], 'the 30 days before the last 30 days');
        $this->assertSame([30000, 2], [$r90['sales']['sales_cents'], $r90['sales']['orders']]);
        $this->assertSame(30, count($r30['series']));
        $this->assertSame(20000, array_sum(array_column($r30['series'], 'cents')));
        $this->assertSame(30, $this->analytics()->window(999)['days'], 'an unknown period falls back to 30 days');
        $this->actingAs($seller)->get(route('seller.analytics', ['period' => 7]))->assertSee('Last 7 days')->assertSee('R200.00');
    }

    public function test_the_chart_always_has_its_table_and_names_a_zero_baseline(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 50]);
        $this->paidOrder($this->makeBuyer(), [$kettle]);

        $this->actingAs($seller)->get(route('seller.analytics'))->assertSee('sd-bars', false)->assertSee('The baseline is R0.00')->assertSee('Show the figures as a table')->assertSee('Sales by day');
    }

    public function test_views_carts_orders_and_conversion_are_counted_per_person_per_day_and_conversion_needs_views(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 50, 'name' => 'Viewed Kettle']);
        $quiet = $this->makeListing($seller, ['price' => 20, 'stock' => 5, 'name' => 'Quiet Spoon']);
        $this->paidOrder($this->makeBuyer(), [$kettle]);
        $this->paidOrder($this->makeBuyer(), [$quiet]); // sold, never viewed

        foreach (['aaaa', 'bbbb', 'cccc', 'cccc'] as $i => $actor) { // cccc twice on the same day is one person
            $this->event('product_view', $kettle, $actor, $i < 2 ? 'search' : 'category');
        }
        $this->event('product_view', $kettle, 'aaaa', 'search', now()->subDays(2)); // the same person on another day counts again
        $this->event('add_to_cart', $kettle, 'aaaa');
        $this->event('product_view', $kettle, 'dddd', 'direct', now()->subDays(80)); // outside the period

        $w = $this->analytics()->window(30);
        $engagement = $this->analytics()->engagement($seller->store, $w['from'], $w['to']);
        $this->assertSame([4, 3, 1], [$engagement['views'], $engagement['from_search'], $engagement['carts']]);

        $rows = $this->analytics()->products($seller->store, $w['from'], $w['to'])->keyBy(fn ($r) => $r['product']->name);
        $this->assertSame([4, 1, 1, 25.0], [$rows['Viewed Kettle']['views'], $rows['Viewed Kettle']['carts'], $rows['Viewed Kettle']['orders'], $rows['Viewed Kettle']['orders_per_view']]);
        $this->assertNull($rows['Quiet Spoon']['orders_per_view'], 'no conversion without views');
        $this->actingAs($seller)->get(route('seller.analytics'))->assertSee('No views')->assertSee('25%')->assertSee('does not keep which words someone searched for');
    }

    public function test_events_of_another_store_never_count(): void
    {
        [$seller, $kettle] = $this->shippingSeller();
        $other = $this->makeSeller();
        $theirs = $this->makeListing($other);
        $this->event('product_view', $theirs, 'zzzz');

        $w = $this->analytics()->window(30);
        $this->assertSame(0, $this->analytics()->engagement($seller->store, $w['from'], $w['to'])['views']);
        $this->actingAs($seller)->get(route('seller.analytics'))->assertSee('No data yet');
    }

    public function test_inventory_counts_stock_value_low_and_out_for_live_listings_only(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['price' => 100, 'stock' => 10]);                                   // 1000 of value
        $this->makeListing($seller, ['price' => 50, 'stock' => 2, 'low_stock_threshold' => 5]);         // 100, low
        $this->makeListing($seller, ['price' => 10, 'stock' => 0]);                                      // out
        $this->makeListing($seller, ['price' => 1000, 'stock' => 50, 'status' => 'pending_approval']);  // not live: not in the value
        $this->makeListing($seller, ['price' => 1000, 'stock' => 1, 'is_active' => false]);             // switched off: neither low nor out

        $inv = $this->analytics()->inventory($seller->store);

        $this->assertSame([110000, 12, 1, 1], [$inv['value_cents'], $inv['units'], $inv['low'], $inv['out']]);
        $this->assertTrue($inv['has_data']);
        $this->assertFalse($this->analytics()->inventory($this->makeSeller()->store)['has_data']);
    }

    public function test_returns_and_the_return_rate_use_deliveries_in_the_period(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['stock' => 50]);
        $none = $this->analytics()->returns($seller->store, now()->subDays(29)->startOfDay(), now());
        $this->assertNull($none['rate'], 'nothing delivered: no rate, not 0%');

        foreach (range(1, 4) as $i) {
            [$order, $group, $line, $buyer] = $this->paidParcel($kettle);
            $this->shipParcel($group);
            $this->advanceAll($order->fresh(), OrderStatus::Delivered);
            if ($i === 1) {
                $this->returnFor($line, $buyer);
            }
        }

        $r = $this->analytics()->returns($seller->store, now()->subDays(29)->startOfDay(), now());
        $this->assertSame([4, 1, 25.0], [$r['delivered'], $r['requested'], $r['rate']]);
    }

    public function test_fulfilment_counts_shipped_on_time_against_the_handling_time_and_skips_what_is_not_due_yet(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['stock' => 50]);
        // Monday 3 Nov 2025: the parcel is paid, 1 working day to pack (deadline Tuesday end of day).
        Carbon::setTestNow('2025-11-03 09:00:00');
        [$o1, $g1] = $this->paidParcel($kettle);
        [$o2, $g2] = $this->paidParcel($kettle);
        Carbon::setTestNow('2025-11-04 10:00:00');
        $this->shipParcel($g1);   // on time
        Carbon::setTestNow('2025-11-07 10:00:00');
        $this->shipParcel($g2);   // late
        Carbon::setTestNow('2025-11-07 11:00:00');
        [$o3, $g3] = $this->paidParcel($kettle); // paid Friday, due Monday: not due yet

        $f = $this->analytics()->fulfilment($seller->store, Carbon::parse('2025-11-01'), Carbon::parse('2025-11-07 12:00:00'));

        $this->assertSame([2, 1, 50.0, 2], [$f['due'], $f['on_time'], $f['rate'], $f['shipped']]);
        $this->assertSame(61.0, $f['median_hours'], 'the middle of 25 and 97 hours');
    }

    public function test_reviews_count_published_reviews_and_the_reply_rate(): void
    {
        [$seller, $kettle] = $this->shippingSeller();
        $a = Review::factory()->create(['product_id' => $kettle->id, 'rating' => 5]);
        $b = Review::factory()->create(['product_id' => $kettle->id, 'rating' => 2]);
        Review::factory()->create(['product_id' => $kettle->id, 'rating' => 1])->forceFill(['status' => 'held'])->save(); // held reviews do not count
        ReviewResponse::query()->create(['review_id' => $a->id, 'user_id' => $seller->id, 'store_id' => $seller->store->id, 'body' => 'Thank you.']);
        app(StoreRatings::class)->refresh($seller->store->id);

        $w = $this->analytics()->window(30);
        $r = $this->analytics()->reviews($seller->store, $w['from'], $w['to']);

        $this->assertSame([2, 3.5, 2, 1, 50.0], [$r['count'], $r['average'], $r['all_time_count'], $r['responded'], $r['response_rate']]);
        $this->assertNull($r['store_average'], 'below five reviews the store rating stays hidden, as it does to shoppers');
    }

    public function test_the_orders_csv_has_the_sellers_own_lines_and_no_personal_data_and_no_formulas(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['name' => '=HYPERLINK("http://evil.test")', 'price' => 100]);
        [$order, , , $buyer] = $this->paidParcel($kettle);
        $other = $this->makeSeller();
        $this->paidOrder($this->makeBuyer(), [$this->makeListing($other, ['name' => 'Rival Secret Product'])]);

        $csv = $this->actingAs($seller)->get(route('seller.analytics.export', ['what' => 'orders', 'period' => 30]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertStringContainsString($order->reference(), $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'a cell that starts like a formula is neutralised');
        $this->assertStringNotContainsString('Rival Secret Product', $csv);
        foreach ([$buyer->email, $buyer->name, 'Thandiwe Mokoena', 'Protea Lane', '+27 82'] as $personal) {
            $this->assertStringNotContainsString($personal, $csv);
        }
        $this->assertSame(2, count(array_filter(explode("\n", trim($csv)))), 'a header and one line');
        $this->get(route('seller.analytics.export', ['what' => 'secrets']))->assertNotFound();
    }

    public function test_the_products_csv_has_every_listing_with_its_period_figures(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['name' => 'Csv Kettle', 'price' => 100, 'sku' => 'CSV-1']);
        $this->makeListing($seller, ['name' => 'Csv Unsold Spoon']);
        $this->paidOrder($this->makeBuyer(), [$kettle]);
        $this->event('product_view', $kettle, 'aaaa');

        $csv = $this->actingAs($seller)->get(route('seller.analytics.export', ['what' => 'products', 'period' => 30]))->streamedContent();

        $this->assertStringContainsString('"Csv Kettle",CSV-1,approved,yes,100.00', $csv);
        $this->assertStringContainsString('Csv Unsold Spoon', $csv);
        $this->assertStringContainsString(',1,0,1,1,100.00,100%', $csv);
    }

    public function test_the_analytics_page_costs_the_same_queries_for_a_small_and_a_busy_store(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['stock' => 500]);
        $this->paidOrder($this->makeBuyer(), [$kettle]);
        $this->actingAs($seller)->get(route('seller.analytics'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('seller.analytics'))->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            $p = $this->makeListing($seller, ['stock' => 20]);
            $this->paidOrder($this->makeBuyer(), [$p]);
            $this->event('product_view', $p, 'v'.$i);
        }
        $this->get(route('seller.analytics'))->assertOk();
        DB::flushQueryLog();
        $this->get(route('seller.analytics'))->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'no query per product or per order');
    }
}
