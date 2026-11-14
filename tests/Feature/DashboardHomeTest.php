<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use App\Models\User;
use App\Services\Dashboard\BuyerHome;
use App\Services\Dashboard\OperatorHome;
use App\Services\Dashboard\SalesChart;
use App\Services\Dashboard\SellerHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/**
 * The dashboards (R-51): the seller's, the buyer's and the operator's first pages. Two routes share one data class each, the state decides the headline and the
 * primary action (the R-50 rule stays the fallback), every query is for one person or store, and an empty page says so.
 */
class DashboardHomeTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    private function primaryCount(string $html): int
    {
        return preg_match_all('/\sdata-primary-action(?=[\s=>])/', $html);
    }

    public function test_both_seller_routes_render_the_same_view_from_the_same_data(): void
    {
        [$seller] = $this->shippingSeller(['name' => 'Twin Routes']);
        $a = $this->actingAs($seller)->get(route('dashboard'))->assertOk()->assertViewIs('seller.dashboard');
        $b = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk()->assertViewIs('seller.dashboard');

        $this->assertSame(array_keys($a->viewData('needs')), array_keys($b->viewData('needs')));
        $this->assertSame($a->viewData('headline'), $b->viewData('headline'));
        $this->assertSame($a->viewData('primary'), $b->viewData('primary'));
        $this->assertSame(1, $this->primaryCount($a->getContent()));
        $this->assertSame(1, $this->primaryCount($b->getContent()));

        // The period works on both: the page honours it, and a value that is not one of the four falls back to 30 days.
        $this->get(route('dashboard', ['period' => 7]))->assertOk()->assertViewHas('window', fn ($w) => $w['days'] === 7);
        $this->get(route('dashboard', ['period' => 'drop table']))->assertOk()->assertViewHas('window', fn ($w) => $w['days'] === 30);
    }

    public function test_both_buyer_routes_render_the_same_view_from_the_same_data(): void
    {
        $buyer = $this->makeBuyer();
        $a = $this->actingAs($buyer)->get(route('dashboard'))->assertOk()->assertViewIs('account.dashboard');
        $b = $this->actingAs($buyer)->get(route('account.home'))->assertOk()->assertViewIs('account.dashboard');

        $this->assertSame($a->viewData('headline'), $b->viewData('headline'));
        $this->assertSame($a->viewData('primary'), $b->viewData('primary'));
        $this->assertSame(1, $this->primaryCount($a->getContent()));
        $this->assertSame(1, $this->primaryCount($b->getContent()));
    }

    public function test_parcels_to_ship_override_the_landing_rule_and_the_headline_counts_them(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $this->paidParcel($product);

        $page = $this->actingAs($seller)->get(route('dashboard'))->assertOk();
        $page->assertSee('1 parcel is waiting for you to pack and ship.')->assertSee('Open orders to ship');
        $this->assertSame(route('seller.orders.index', ['tab' => 'to_ship']), $page->viewData('primary')['href']);
        $this->assertSame('ship', $page->viewData('primary')['state']);
        $this->assertSame(1, $this->primaryCount($page->getContent()), 'still exactly one primary action');
        $this->get(route('seller.orders.index', ['tab' => 'to_ship']))->assertOk();
    }

    public function test_an_unpaid_order_overrides_the_landing_rule_for_the_buyer(): void
    {
        [, $product] = $this->shippingSeller();
        $buyer = $this->makeBuyer();
        $this->enablePaystack();
        $this->placeOrder($buyer, [[$product, 1]]);

        $page = $this->actingAs($buyer)->get(route('dashboard'))->assertOk();
        $page->assertSee('1 order is waiting for payment.')->assertSee('See the unpaid order');
        $this->assertSame(route('orders.index', ['status' => 'awaiting_payment']), $page->viewData('primary')['href']);
        $this->assertSame(1, $this->primaryCount($page->getContent()));
    }

    public function test_the_fallback_is_the_r50_rule_when_nothing_is_waiting(): void
    {
        $this->assertSame('fallback', BuyerHome::primary(0, true)['state']);
        $this->assertSame(route('products.index'), BuyerHome::primary(0, true)['href']);
        $this->assertSame(route('sell.start'), BuyerHome::primary(0, false)['href']);
        $this->assertSame(route('seller.products.create'), SellerHome::primary(['toShip' => 0], 0)['href']);
        $this->assertSame(route('seller.products.index'), SellerHome::primary(['toShip' => 0], 4)['href']);
    }

    public function test_the_sellers_photographs_and_rows_are_only_their_own_stores(): void
    {
        $a = $this->makeSeller(['name' => 'Alpha Store']);
        $b = $this->makeSeller(['name' => 'Bravo Store']);
        $mine = $this->makeListing($a, ['name' => 'Alpha Lamp', 'stock' => 1]);
        $theirs = $this->makeListing($b, ['name' => 'Bravo Secret Lamp', 'stock' => 1]);
        ProductImage::create(['product_id' => $mine->id, 'path' => 'product-images/a/mine.jpg', 'sort_order' => 0]);
        ProductImage::create(['product_id' => $theirs->id, 'path' => 'product-images/b/theirs.jpg', 'sort_order' => 0]);

        $this->assertSame([$mine->id], array_keys(SellerHome::thumbs($a->store, [$mine->id, $theirs->id])), 'a product id from another store never yields a photograph');

        $html = $this->actingAs($a)->get(route('dashboard'))->assertOk()->assertSee('Alpha Lamp')->getContent();
        $this->assertStringContainsString('mine.jpg', $html, 'the low-stock row carries the real photograph');
        $this->assertStringNotContainsString('theirs.jpg', $html);
        $this->assertStringNotContainsString('Bravo Secret Lamp', $html);
    }

    public function test_the_headline_and_figures_match_the_source(): void
    {
        [$seller, $kettle] = $this->shippingSeller(['name' => 'Figures Store'], ['price' => 200, 'stock' => 20]);
        $this->paidParcel($kettle);

        $page = $this->actingAs($seller)->get(route('dashboard'))->assertOk();
        $sales = $page->viewData('sales');
        $this->assertSame(20000, $sales['sales_cents']);
        $page->assertSee('R200.00')->assertSee('data-money="20000"', false);
        // The chart's one bar is that sale and its table says the same amount.
        $chart = $page->viewData('chart');
        $this->assertTrue($chart['hasSales']);
        $this->assertSame([20000], array_values(array_filter(array_column($chart['bars'], 'cents'))));
        $this->assertSame('R200.00', $chart['rows'][0]['amount']);
    }

    public function test_the_chart_geometry_draws_nothing_for_a_day_without_sales_and_rounds_the_axis_up(): void
    {
        $series = [['label' => '1 Nov', 'cents' => 0], ['label' => '2 Nov', 'cents' => 82800], ['label' => '3 Nov', 'cents' => 0]];
        $chart = SalesChart::build($series);

        $this->assertSame([null, true, null], array_map(fn ($b) => $b['path'] === null ? null : true, $chart['bars']));
        $this->assertSame('3 Nov: no sales', $chart['bars'][2]['tip']);
        $this->assertSame('2 Nov: R828.00', $chart['bars'][1]['tip']);
        $this->assertSame(90000, $chart['max']);
        $this->assertSame('One day had sales, the highest R828.00 (2 Nov).', $chart['summary']);

        $empty = SalesChart::build([['label' => '1 Nov', 'cents' => 0]]);
        $this->assertFalse($empty['hasSales']);
        $this->assertSame('No sales in this period.', $empty['summary']);
        foreach ([[1, 100], [10001, 10100], [90000, 90000], [90001, 100000]] as [$peak, $max]) {
            $this->assertGreaterThanOrEqual($peak, SalesChart::niceMax($peak));
        }
    }

    public function test_a_new_shop_says_what_is_true_and_charts_nothing(): void
    {
        $seller = $this->makeSeller(['name' => 'Fresh Shop']);

        $page = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();
        $page->assertSee('No data yet')->assertSee('No sales to chart yet')->assertSee('No orders for your store yet.')->assertSee('No earnings recorded yet.');
        $this->assertFalse($page->viewData('chart')['hasSales']);
    }

    public function test_a_buyer_sees_only_their_own_orders_and_the_newest_listings_with_photographs(): void
    {
        $seller = $this->makeSeller(['name' => 'Shelf Store']);
        $this->withDelivery($seller);
        $lamp = $this->makeListing($seller, ['name' => 'Shelf Lamp']);
        ProductImage::create(['product_id' => $lamp->id, 'path' => 'product-images/s/lamp.jpg', 'sort_order' => 0]);
        $me = $this->makeBuyer();
        $other = $this->makeBuyer();
        $this->enablePaystack();
        $mine = $this->placeOrder($me, [[$lamp, 1]]);
        $theirs = $this->placeOrder($other, [[$lamp, 1]]);

        $page = $this->actingAs($me)->get(route('account.home'))->assertOk()->assertSee($mine->reference())->assertDontSee($theirs->reference());
        $page->assertSee('Shelf Lamp')->assertSee('lamp.jpg', false);
        $this->assertSame(1, $page->viewData('orderCount'));
    }

    public function test_the_operator_page_leads_with_the_longest_wait_and_a_clear_desk_leads_to_the_audit_trail(): void
    {
        $operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);

        $clear = $this->actingAs($operator)->get(route('operator.home'))->assertOk();
        $clear->assertSee('Nothing is waiting for you.')->assertSee('Open the audit trail');
        $this->assertSame(route('operator.audit'), $clear->viewData('primary')['href']);
        $this->assertSame(1, $this->primaryCount($clear->getContent()));

        $this->makeListing($this->makeSeller(), ['status' => 'pending_approval', 'name' => 'Waiting Lamp']);
        $busy = $this->get(route('operator.home'))->assertOk();
        $busy->assertSee('1 thing is waiting for a decision.')->assertSee('Decide the oldest: listings');
        $this->assertSame(1, $this->primaryCount($busy->getContent()));
        $this->assertSame(1, $busy->viewData('total'));
        $this->assertSame('Decide the oldest: listings', OperatorHome::primary($busy->viewData('waiting'))['label']);
    }

    public function test_the_operator_page_is_for_operators_only(): void
    {
        $this->actingAs($this->makeBuyer())->get(route('operator.home'))->assertForbidden();
    }
}
