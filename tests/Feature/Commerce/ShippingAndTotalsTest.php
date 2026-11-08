<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\ShippingMethodRegion;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PriceChangedException;
use App\Services\Shipping\BusinessDays;
use App\Services\Shipping\Region;
use App\Services\Shipping\ShippingQuote;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class ShippingAndTotalsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function quote(): ShippingQuote
    {
        return app(ShippingQuote::class);
    }

    public function test_a_flat_rate_method_prices_every_group_and_labels_its_dates_as_an_estimate(): void
    {
        Carbon::setTestNow('2026-11-09 09:00:00'); // a Monday
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 75, 'handling_days' => 1, 'window_min_days' => 2, 'window_max_days' => 4]);

        $group = $this->quote()->forStores([$seller->store->id => 20000], new Region('ZA', 'Gauteng', '2193'))[$seller->store->id];
        $option = $group->cheapest();

        $this->assertSame(7500, $option->priceCents);
        $this->assertSame('2026-11-12', $option->estimatedFrom->toDateString(), 'one working day to dispatch, then two');
        $this->assertSame('2026-11-16', $option->estimatedTo->toDateString(), 'weekends are skipped');
        $this->assertStringContainsString('Estimated delivery', $option->estimateLabel);
        $this->assertStringContainsString('not a promise', $option->estimateLabel);
    }

    public function test_business_days_skip_weekends(): void
    {
        $this->assertSame('2026-11-16', BusinessDays::add(Carbon::parse('2026-11-13'), 1)->toDateString());
        $this->assertSame('2026-11-13', BusinessDays::add(Carbon::parse('2026-11-13'), 0)->toDateString());
    }

    public function test_free_over_a_threshold_becomes_free_and_says_how_much_is_left(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['type' => 'free_over', 'price' => 60, 'free_over' => 500]);
        $sid = $seller->store->id;

        $under = $this->quote()->forStores([$sid => 35000], new Region('ZA', 'Gauteng', '2193'))[$sid]->cheapest();
        $over = $this->quote()->forStores([$sid => 50000], new Region('ZA', 'Gauteng', '2193'))[$sid]->cheapest();

        $this->assertSame([6000, false, 15000], [$under->priceCents, $under->freeApplied, $under->remainingForFree(35000)]);
        $this->assertSame([0, true, null], [$over->priceCents, $over->freeApplied, $over->remainingForFree(50000)]);
    }

    public function test_collection_is_free_needs_no_region_and_carries_the_address(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['name' => 'Collect', 'type' => 'collection', 'price' => 0, 'collection_address' => '22 Main Road, Cape Town', 'handling_days' => 1]);

        $option = $this->quote()->forStores([$seller->store->id => 1000], null)[$seller->store->id]->cheapest();

        $this->assertTrue($option->isCollection());
        $this->assertSame(0, $option->priceCents);
        $this->assertSame('22 Main Road, Cape Town', $option->collectionAddress);
        $this->assertStringContainsString('Estimated ready for collection', $option->estimateLabel);
    }

    public function test_region_rules_decide_where_a_method_applies_and_may_override_the_price(): void
    {
        $seller = $this->makeSeller();
        $method = $this->withDelivery($seller, ['name' => 'Regional courier', 'price' => 100]);
        ShippingMethodRegion::create(['shipping_method_id' => $method->id, 'country' => 'ZA', 'province' => 'Gauteng', 'price' => 50]);
        ShippingMethodRegion::create(['shipping_method_id' => $method->id, 'country' => 'ZA', 'province' => 'Western Cape', 'postal_from' => '8000', 'postal_to' => '8099']);
        $sid = $seller->store->id;
        $price = fn (?Region $r) => ($this->quote()->forStores([$sid => 1000], $r)[$sid]->cheapest())?->priceCents;

        $this->assertSame(5000, $price(new Region('ZA', 'Gauteng', '2000')), 'the Gauteng rule has its own price');
        $this->assertSame(5000, $price(new Region('ZA', 'gauteng', '1685')), 'province is not case sensitive');
        $this->assertSame(10000, $price(new Region('ZA', 'Western Cape', '8001')), 'inside the postal range, the method price');
        $this->assertNull($price(new Region('ZA', 'Western Cape', '7700')), 'outside the postal range');
        $this->assertNull($price(new Region('ZA', 'KwaZulu-Natal', '4001')), 'no rule for the province');
        $this->assertNull($price(new Region('GB', null, 'SW1A 1AA')), 'no rule for the country');
        $this->assertNull($price(null), 'an unknown destination cannot match a region-limited method');
    }

    public function test_a_store_with_methods_that_do_not_reach_the_address_cannot_be_ordered_from_there(): void
    {
        $seller = $this->makeSeller();
        $method = $this->withDelivery($seller);
        ShippingMethodRegion::create(['shipping_method_id' => $method->id, 'country' => 'ZA', 'province' => 'Gauteng']);
        $product = $this->makeListing($seller);
        $address = Address::factory()->create(['user_id' => ($buyer = $this->makeBuyer())->id, 'province' => 'Limpopo', 'postal_code' => '0700']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('does not deliver to this address');
        $this->placeOrder($buyer, [[$product, 1]], ['address_id' => $address->id, 'shipping_address' => null]);
    }

    public function test_a_store_with_no_methods_at_all_sells_with_delivery_arranged_by_the_seller(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 100]);
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);

        $group = $order->groups()->first();
        $this->assertSame(['arranged', 0], [$group->method_type, $group->delivery_cents]);
        $this->assertSame('100.00', $order->total);
    }

    public function test_delivery_is_added_per_seller_group_and_the_order_total_is_items_minus_discount_plus_delivery(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $this->withDelivery($a, ['price' => 60]);
        $this->withDelivery($b, ['price' => 90]);
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'TEN', 'discount_type' => 'percent', 'discount_value' => 10]);
        $address = Address::factory()->create(['user_id' => ($buyer = $this->makeBuyer())->id]);

        $order = $this->placeOrder($buyer, [[$this->makeListing($a, ['price' => 200]), 1], [$this->makeListing($b, ['price' => 50]), 2]], ['address_id' => $address->id, 'shipping_address' => null, 'promo_code' => 'TEN']);

        $this->assertSame('300.00', $order->subtotal);
        $this->assertSame('20.00', $order->discount_amount);
        $this->assertSame('150.00', $order->delivery_total);
        $this->assertSame('430.00', $order->total);
        $this->assertSame([6000, 9000], $order->groups()->orderBy('id')->pluck('delivery_cents')->all());
        $this->assertSame(2, $order->groups()->count());
        $this->assertSame($order->groups()->orderBy('id')->first()->id, $order->items()->orderBy('id')->first()->order_group_id);
        $this->assertNotNull($order->groups()->first()->estimated_to, 'the estimate is stored on the parcel');
    }

    public function test_cancelling_all_of_a_sellers_lines_gives_back_their_delivery_fee_too(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $this->withDelivery($a, ['price' => 60]);
        $this->withDelivery($b, ['price' => 90]);
        $address = Address::factory()->create(['user_id' => ($buyer = $this->makeBuyer())->id]);
        $order = $this->placeOrder($buyer, [[$this->makeListing($a, ['price' => 200]), 1], [$this->makeListing($b, ['price' => 50]), 1]], ['address_id' => $address->id, 'shipping_address' => null]);
        $this->assertSame('400.00', $order->total);

        app(OrderLifecycle::class)->cancelItem($order->items->firstWhere('seller_id', $b->id), $buyer);

        $this->assertSame('260.00', $order->fresh()->total);
        $this->assertSame('60.00', $order->fresh()->delivery_total);
    }

    public function test_vat_is_the_part_already_inside_the_price_for_vat_registered_stores_only(): void
    {
        $vat = $this->makeSeller(['vat_registered' => true, 'vat_number' => '4012345678']);
        $plain = $this->makeSeller();
        $this->withDelivery($vat, ['price' => 15]);
        $address = Address::factory()->create(['user_id' => ($buyer = $this->makeBuyer())->id]);

        $order = $this->placeOrder($buyer, [[$this->makeListing($vat, ['price' => 100]), 1], [$this->makeListing($plain, ['price' => 100]), 1]], ['address_id' => $address->id, 'shipping_address' => null]);

        // (100 + 15) x 0.15 / 1.15 = 15.00 included in the VAT-registered store's R115.
        $this->assertSame('15.00', $order->vat_total);
        $this->assertSame('215.00', $order->total, 'VAT is already in the prices: it is never added on top');
        $this->assertSame(1500, $order->groups()->where('vat_registered', true)->first()->vat_cents);
        $this->assertSame(0, $order->groups()->where('vat_registered', false)->first()->vat_cents);
    }

    public function test_a_free_shipping_promo_waives_that_sellers_delivery_only(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $this->withDelivery($a, ['price' => 60]);
        $this->withDelivery($b, ['price' => 90]);
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'FREESHIP', 'discount_type' => 'fixed', 'discount_value' => 1, 'free_shipping' => true]);
        $address = Address::factory()->create(['user_id' => ($buyer = $this->makeBuyer())->id]);

        $order = $this->placeOrder($buyer, [[$this->makeListing($a, ['price' => 100]), 1], [$this->makeListing($b, ['price' => 100]), 1]], ['address_id' => $address->id, 'shipping_address' => null, 'promo_code' => 'FREESHIP']);

        $this->assertSame('90.00', $order->delivery_total);
        $this->assertSame('289.00', $order->total);
        $this->assertTrue($order->promo_free_shipping);
    }

    public function test_the_buyer_chooses_among_a_stores_options_but_cannot_invent_one(): void
    {
        $seller = $this->makeSeller();
        $cheap = $this->withDelivery($seller, ['name' => 'Post', 'price' => 40, 'window_max_days' => 9]);
        $fast = $this->withDelivery($seller, ['name' => 'Overnight', 'price' => 120, 'window_min_days' => 1, 'window_max_days' => 1]);
        $other = $this->withDelivery($this->makeSeller(), ['price' => 0]);
        $product = $this->makeListing($seller, ['price' => 100]);
        $buyer = $this->makeBuyer();

        $order = $this->placeOrder($buyer, [[$product, 1]], ['shipping_methods' => [$seller->store->id => $fast->id]]);
        $this->assertSame('220.00', $order->total);
        $this->assertSame('Overnight', $order->groups()->first()->method_name);

        $this->makeCart($buyer, [[$product, 1]]);

        foreach ([$other->id, 999999, 'free'] as $forged) {
            try {
                app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street', 'shipping_methods' => [$seller->store->id => $forged]]);
                $this->fail('a method the store does not offer must be refused');
            } catch (CheckoutException $e) {
                $this->assertStringContainsString('not available', $e->getMessage());
            }
        }

        $this->assertSame(0, Order::query()->where('user_id', $buyer->id)->count() - 1, 'no order was created by a forged choice');
        $this->assertSame(1, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count(), 'the cart is untouched');
    }

    public function test_an_inactive_method_cannot_be_chosen(): void
    {
        $seller = $this->makeSeller();
        $off = $this->withDelivery($seller, ['is_active' => false, 'price' => 0]);
        $this->withDelivery($seller, ['price' => 50]);

        $this->expectException(CheckoutException::class);
        $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]], ['shipping_methods' => [$seller->store->id => $off->id]]);
    }

    public function test_the_total_the_buyer_was_shown_must_match_or_nothing_is_placed(): void
    {
        $seller = $this->makeSeller();
        $method = $this->withDelivery($seller, ['price' => 60]);
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 5]);
        $buyer = $this->makeBuyer();
        $this->makeCart($buyer, [[$product, 2]]);

        // The seller raises the delivery fee between the cart page and the button.
        $method->update(['price' => 80]);

        try {
            app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street', 'expected_total_cents' => 26000]);
            $this->fail('a changed price must stop the order');
        } catch (PriceChangedException $e) {
            $this->assertSame([26000, 28000], [$e->expectedCents, $e->actualCents]);
            $this->assertStringContainsString('R280.00', $e->getMessage());
        }

        $this->assertSame(5, $product->fresh()->stock, 'nothing was reserved');
        $this->assertSame(1, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, Order::query()->count());

        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street', 'expected_total_cents' => 28000]);
        $this->assertSame('280.00', $order->total);
    }

    public function test_a_price_edit_between_cart_and_checkout_is_caught_by_the_same_check(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 100]);
        $buyer = $this->makeBuyer();
        $this->makeCart($buyer, [[$product, 1]]);
        $shown = app(CartQuote::class)->quote(app(CartManager::class)->lines($buyer))->totalCents;

        $product->update(['price' => 130]);

        $this->expectException(PriceChangedException::class);
        app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street', 'expected_total_cents' => $shown]);
    }

    public function test_the_cart_quote_equals_the_order_total_checkout_charges(): void
    {
        $a = $this->makeSeller(['vat_registered' => true]);
        $b = $this->makeSeller();
        $this->withDelivery($a, ['type' => 'free_over', 'price' => 70, 'free_over' => 150]);
        $this->withDelivery($b, ['price' => 33.33]);
        PromoCode::factory()->create(['store_id' => $b->store->id, 'code' => 'ODD', 'discount_type' => 'percent', 'discount_value' => 12.5]);
        $buyer = $this->makeBuyer();
        $address = Address::factory()->create(['user_id' => $buyer->id]);
        $this->makeCart($buyer, [[$this->makeListing($a, ['price' => 49.99]), 3], [$this->makeListing($b, ['price' => 19.95]), 3]]);

        $lines = app(CartManager::class)->lines($buyer);
        $quote = app(CartQuote::class)->quote($lines, Region::from($address), 'odd');

        $this->assertTrue($quote->canCheckout());
        $this->assertNull($quote->promoProblem);

        $order = app(CheckoutService::class)->place($buyer, ['address_id' => $address->id, 'promo_code' => 'odd', 'expected_total_cents' => $quote->totalCents]);

        $this->assertSame($order->totalCents(), $quote->totalCents);
        $this->assertSame(Money::toCents($order->vat_total), $quote->vatCents);
        $this->assertSame(Money::toCents($order->delivery_total), $quote->deliveryCents);
        $this->assertSame(Money::toCents($order->discount_amount), $quote->discountCents);
    }

    public function test_a_bad_promo_in_the_quote_is_reported_not_applied(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->makeCart($buyer, [[$this->makeListing($seller, ['price' => 100]), 1]]);

        $quote = app(CartQuote::class)->quote(app(CartManager::class)->lines($buyer), null, 'NOPE');

        $this->assertNotNull($quote->promoProblem);
        $this->assertSame(10000, $quote->totalCents);
    }

    public function test_quote_marks_lines_that_cannot_be_bought_and_groups_that_cannot_be_delivered(): void
    {
        $seller = $this->makeSeller();
        $method = $this->withDelivery($seller);
        ShippingMethodRegion::create(['shipping_method_id' => $method->id, 'country' => 'ZA', 'province' => 'Gauteng']);
        $product = $this->makeListing($seller, ['stock' => 1]);
        $buyer = $this->makeBuyer();
        $this->makeCart($buyer, [[$product, 1]]);
        $product->update(['stock' => 0]);

        $quote = app(CartQuote::class)->quote(app(CartManager::class)->lines($buyer), new Region('ZA', 'Limpopo', '0700'));

        $this->assertFalse($quote->canCheckout());
        $this->assertCount(1, $quote->problems);
        $this->assertFalse($quote->groups[$seller->store->id]['can_deliver']);
    }

    public function test_the_tamper_surface_prices_cannot_come_from_the_request(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 60]);
        $product = $this->makeListing($seller, ['price' => 100]);
        $buyer = $this->makeBuyer();
        $this->makeCart($buyer, [[$product, 1]]);

        $this->actingAs($buyer)->post(route('cart.checkout'), [
            'checkout_token' => (string) Str::uuid(), 'shipping_address' => '1 Test Street',
            'delivery_total' => 0, 'total' => 1, 'price' => 1, 'unit_price' => 1, 'shipping_price' => 0, 'vat_total' => 0,
        ]);

        $order = Order::query()->firstOrFail();
        $this->assertSame('160.00', $order->total);
        $this->assertSame(6000, $order->groups()->first()->delivery_cents);
        $this->assertSame('100.00', $order->items->first()->unit_price);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    private function makeCart($buyer, array $lines): void
    {
        foreach ($lines as [$product, $quantity]) {
            CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        }
    }
}
