<?php

namespace Tests\Feature\Currency;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SellerDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * An order remembers the currency it was placed in, so history survives a
 * later change to SHOP_CURRENCY, and a seller's totals are never a sum of
 * unlike currencies.
 */
class OrderCurrencyTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_a_new_order_records_the_storefront_currency_at_checkout(): void
    {
        config(['shop.currency' => 'ZAR']);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['price' => 50]), 1]]);

        $this->assertSame('ZAR', $order->currency);
        $this->assertSame('ZAR', Order::find($order->id)->currency);
    }

    public function test_an_order_created_without_naming_a_currency_gets_the_storefront_one(): void
    {
        config(['shop.currency' => 'EUR']);

        $order = Order::factory()->create();

        $this->assertSame('EUR', $order->fresh()->currency);
    }

    public function test_order_pages_keep_the_original_currency_after_the_setting_changes(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56]), 1]]);

        config(['shop.currency' => 'EUR']);

        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertSee('R1,234.56')->assertDontSee('€1,234.56');
        $this->actingAs($buyer)->get(route('orders.index'))->assertSee('R1,234.56')->assertDontSee('€1,234.56');
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertSee('R1,234.56')->assertDontSee('€1,234.56');
        // The catalogue, which is priced today, follows the setting.
        $this->get(route('products.index'))->assertSee('€1,234.56');
    }

    public function test_a_sellers_delivered_revenue_is_reported_per_currency_never_mixed(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['price' => 100]);

        config(['shop.currency' => 'ZAR']);
        $zar = $this->placeOrder($this->makeBuyer(), [[$listing, 2]]);
        config(['shop.currency' => 'EUR']);
        $eur = $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);

        foreach ([$zar, $eur] as $order) {
            $order->items()->update(['status' => 'delivered']);
        }

        $summary = app(SellerDashboard::class)->summary($seller);

        $this->assertSame(['EUR' => 10000, 'ZAR' => 20000], $summary['revenueByCurrency']);
        $this->assertArrayNotHasKey('revenueCents', $summary, 'a single revenue figure would mix currencies');
    }

    public function test_the_dashboard_shows_one_row_per_currency(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['price' => 1234.56]);

        config(['shop.currency' => 'ZAR']);
        $zar = $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);
        config(['shop.currency' => 'EUR']);
        $eur = $this->placeOrder($this->makeBuyer(), [[$listing, 2]]);
        foreach ([$zar, $eur] as $order) {
            $order->items()->update(['status' => 'delivered']);
        }

        $page = $this->actingAs($seller)->get(route('dashboard'))->assertOk();

        $page->assertSee('R1,234.56')->assertSee('€2,469.12');
        // No grand total that adds rand to euro.
        $page->assertDontSee('3,703.68');
    }

    public function test_a_seller_with_no_sales_sees_zero_in_the_storefront_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();

        $this->assertSame(['ZAR' => 0], app(SellerDashboard::class)->summary($seller)['revenueByCurrency']);
        $this->actingAs($seller)->get(route('dashboard'))->assertSee('R0.00');
    }

    public function test_the_recent_orders_net_is_in_each_orders_own_currency(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['price' => 100]);
        config(['shop.currency' => 'EUR']);
        $order = $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);
        config(['shop.currency' => 'ZAR']);

        $recent = app(SellerDashboard::class)->summary($seller)['recentOrders'];

        $this->assertSame('EUR', $recent->first()['order']->currency);
        $this->assertSame($order->id, $recent->first()['order']->id);
    }

    public function test_order_lines_carry_no_currency_of_their_own(): void
    {
        $this->assertFalse(in_array('currency', (new OrderItem)->getFillable(), true));
    }
}
