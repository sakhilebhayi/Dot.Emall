<?php

namespace Tests\Feature\Currency;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * Every page that prints money prints it in the storefront currency
 * (config shop.currency) and never as a literal dollar. Prices are chosen
 * to be distinctive (1,234.56 and friends) so a stray match is impossible.
 */
class StorefrontCurrencyTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function seedOrder(string $currency = 'ZAR'): array
    {
        config(['shop.currency' => $currency]);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56, 'compare_price' => 1999.99]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$listing, 2]]);

        return [$seller, $buyer, $listing, $order];
    }

    public function test_the_public_product_pages_print_the_storefront_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56, 'compare_price' => 1999.99]);

        $this->get(route('products.index'))->assertOk()->assertSee('R1,234.56')->assertDontSee('$1,234.56');
        $this->get(route('products.show', $listing))->assertOk()
            ->assertSee('R1,234.56')->assertSee('R1,999.99')
            ->assertDontSee('$1,234.56')->assertDontSee('$1,999.99');
    }

    public function test_changing_the_setting_changes_every_price_on_the_page(): void
    {
        config(['shop.currency' => 'EUR']);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56]);

        $this->get(route('products.show', $listing))->assertSee('€1,234.56')->assertDontSee('R1,234.56')->assertDontSee('$1,234.56');

        config(['shop.currency' => 'CHF']);

        $this->get(route('products.show', $listing))->assertSee('CHF 1,234.56')->assertDontSee('€1,234.56');
    }

    public function test_the_cart_prints_unit_line_and_subtotal_amounts_in_the_storefront_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56]);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $listing->id, 'quantity' => 2]);

        $page = $this->actingAs($buyer)->get(route('cart.index'))->assertOk();

        $page->assertSee('R1,234.56 each')->assertSee('R2,469.12')->assertDontSee('$2,469.12')->assertDontSee('$1,234.56');
    }

    public function test_the_seller_listings_and_promo_codes_print_the_storefront_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1234.56]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'FIXEDOFF', 'discount_type' => 'fixed', 'discount_value' => 150.5]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'PERCENTOFF', 'discount_type' => 'percent', 'discount_value' => 15]);

        $this->actingAs($seller)->get(route('seller.products.index'))->assertOk()->assertSee('R1,234.56')->assertDontSee('$1,234.56');

        $promos = $this->actingAs($seller)->get(route('seller.promo-codes.index'))->assertOk();
        $promos->assertSee('R150.50 off')->assertSee('15% off')->assertDontSee('$150.50');
    }

    public function test_a_promo_percentage_is_never_given_a_currency_symbol(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'PERCENTOFF', 'discount_type' => 'percent', 'discount_value' => 15]);

        $this->actingAs($seller)->get(route('seller.promo-codes.index'))->assertSee('15% off')->assertDontSee('R15')->assertDontSee('15.00 off');
    }

    public function test_the_operator_product_queue_prints_the_storefront_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        Product::factory()->pending()->create(['store_id' => $seller->store->id, 'name' => 'Karoo Copper Kettle', 'price' => 1234.56]);
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $this->actingAs($operator)->get(route('operator.products.index'))->assertOk()->assertSee('R1,234.56')->assertDontSee('$1,234.56');
    }

    public function test_order_pages_print_the_orders_currency_for_buyer_and_seller(): void
    {
        [$seller, $buyer, , $order] = $this->seedOrder('ZAR');

        $buyerPage = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();
        $buyerPage->assertSee('R1,234.56')->assertSee('R2,469.12')->assertDontSee('$2,469.12')->assertDontSee('$1,234.56');

        $this->actingAs($buyer)->get(route('orders.index'))->assertOk()->assertSee('R2,469.12')->assertDontSee('$2,469.12');

        $sellerPage = $this->actingAs($seller)->get(route('orders.show', $order))->assertOk();
        $sellerPage->assertSee('R2,469.12')->assertDontSee('$2,469.12');

        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertSee('R2,469.12')->assertDontSee('$2,469.12');
    }

    public function test_the_order_discount_lines_use_the_order_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'FIXEDOFF', 'discount_type' => 'fixed', 'discount_value' => 200]);
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 1000]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$listing, 1]], ['promo_code' => 'FIXEDOFF']);

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        $page->assertSee('-R200.00')->assertSee('R800.00')->assertDontSee('-$200.00')->assertDontSee('$800.00');
    }

    public function test_the_seller_dashboard_prints_delivered_value_and_recent_orders_in_the_orders_currency(): void
    {
        [$seller, , , $order] = $this->seedOrder('ZAR');
        $order->items()->update(['status' => 'delivered']);
        $order->forceFill(['status' => 'delivered'])->save();

        $page = $this->actingAs($seller)->get(route('dashboard'))->assertOk();

        $page->assertSee('R2,469.12')->assertDontSee('$2,469.12');
    }

    public function test_the_new_order_notification_names_the_orders_currency(): void
    {
        config(['shop.currency' => 'ZAR']);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['name' => 'Karoo Copper Kettle', 'price' => 180]);
        $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);

        $message = $seller->fresh()->notifications()->first()->data['message'];

        $this->assertStringContainsString('1 item worth R180.00', $message);
        $this->assertStringNotContainsString('$', $message);
    }

    public function test_no_page_for_a_default_install_shows_a_dollar_amount(): void
    {
        [$seller, $buyer, $listing, $order] = $this->seedOrder('ZAR');
        $orderId = $order->id;

        foreach ([
            [$buyer, route('orders.show', $orderId)],
            [$buyer, route('orders.index')],
            [$seller, route('dashboard')],
            [$seller, route('seller.orders.index')],
            [null, route('products.show', $listing)],
        ] as [$user, $url]) {
            $response = $user ? $this->actingAs($user)->get($url) : $this->get($url);
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', strip_tags($response->getContent()), $url);
        }

        $this->assertSame('ZAR', Order::find($orderId)->currency);
    }
}
