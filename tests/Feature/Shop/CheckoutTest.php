<?php

namespace Tests\Feature\Shop;

use App\Models\Address;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\ShippingMethod;
use App\Providers\CheckoutServiceProvider;
use App\Services\Cart\CartManager;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Paystack\FakePaystackClient;
use App\Services\Paystack\PaystackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsShopFlows;
    use RefreshDatabase;

    private function shop(array $listing = []): array
    {
        $seller = $this->makeSeller(['name' => 'Checkout Stores']);
        $this->withDelivery($seller, ['price' => 60]);
        $product = $this->makeListing($seller, array_merge(['name' => 'Checkout Kettle', 'price' => 200, 'stock' => 9], $listing));

        return [$seller, $product];
    }

    public function test_the_page_has_four_steps_and_sends_a_guest_with_no_cart_back_to_the_cart(): void
    {
        $this->get(route('checkout.show'))->assertRedirect(route('cart.index'));

        [, $product] = $this->shop();
        $this->addToCart(null, $product);

        $this->get(route('checkout.show'))->assertOk()
            ->assertSee('Contact')->assertSee('Delivery')->assertSee('Payment')->assertSee('Review')
            ->assertSee('Checkout progress')->assertSee('E-mail address')->assertSee('You do not need an account')
            ->assertSee('Total to pay')->assertSee('Set your delivery details first.');
    }

    public function test_a_guest_checks_out_with_an_email_and_is_sent_to_paystack(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop();
        $this->addToCart(null, $product, 2);
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $this->post(route('checkout.delivery'), ['email' => 'Guest.Shopper@Example.test', 'address_id' => 'new', 'address' => $this->address()])->assertRedirect();
        $this->get(route('checkout.show'))->assertSee('Delivery options are ready')->assertSee('Pay R460.00 securely')->assertSee('Estimated delivery', false)->assertSee('Returns and your rights');

        $response = $this->post(route('checkout.place'), $this->payForm(46000, ['email' => 'Guest.Shopper@Example.test']));

        $response->assertRedirect('https://checkout.paystack.com/test-access-code');
        $order = Order::firstOrFail();
        $this->assertNull($order->user_id);
        $this->assertSame('guest.shopper@example.test', $order->guest_email);
        $this->assertSame(46000, $order->totalCents());
        $this->assertSame('Johannesburg', $order->shipping_snapshot['city']);
        $this->assertSame(0, app(CartManager::class)->count(null), 'the session cart is emptied once the order exists');
        $this->assertTrue(app(GuestOrderAccess::class)->hasSessionGrant($order), 'the guest can open their own order in this browser');
        $this->assertSame(1, Payment::count());

        $http->assertSent(function (HttpRequest $r) use ($order) {
            $callback = $r->data()['callback_url'] ?? '';

            return str_contains($r->url(), 'transaction/initialize') && $r['amount'] === 46000
                && str_contains($callback, '/guest/orders/'.$order->id.'/') && str_ends_with($callback, '/payment') && $r['email'] === 'guest.shopper@example.test';
        });
    }

    public function test_a_signed_in_buyer_picks_a_saved_address_and_the_order_keeps_a_snapshot(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop();
        $buyer = $this->makeBuyer();
        $saved = Address::create(['user_id' => $buyer->id, 'is_default_shipping' => true] + $this->address(['recipient_name' => 'Saved Sipho']));
        $this->addToCart($buyer, $product);
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $this->actingAs($buyer)->get(route('checkout.show'))->assertOk()->assertSee('Saved Sipho')->assertSee('Manage your address book')->assertDontSee('E-mail address');
        $this->actingAs($buyer)->post(route('checkout.delivery'), ['address_id' => $saved->id])->assertRedirect();

        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 26000, 'address_id' => $saved->id, 'terms' => '1'])
            ->assertRedirect('https://checkout.paystack.com/test-access-code');

        $order = Order::firstOrFail();
        $this->assertSame($buyer->id, $order->user_id);
        $saved->update(['line1' => 'Moved away 9 Elsewhere Road']);
        $this->assertSame('14 Protea Lane', $order->fresh()->shipping_snapshot['line1'], 'changing the address book never rewrites an order');
    }

    public function test_a_typed_address_can_be_saved_to_the_address_book_and_another_buyers_address_cannot_be_used(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop();
        $buyer = $this->makeBuyer();
        $other = $this->makeBuyer();
        $theirs = Address::create(['user_id' => $other->id] + $this->address());
        $this->addToCart($buyer, $product);
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 26000, 'address_id' => $theirs->id, 'terms' => '1'])
            ->assertSessionHasErrors('address_id');
        $this->assertSame(0, Order::count());

        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 26000, 'address_id' => 'new', 'address' => $this->address(), 'save_address' => '1', 'terms' => '1'])
            ->assertRedirect('https://checkout.paystack.com/test-access-code');
        $this->assertSame(1, Address::where('user_id', $buyer->id)->count());
    }

    public function test_a_failed_validation_keeps_everything_that_was_typed(): void
    {
        [, $product] = $this->shop();
        $this->addToCart(null, $product);

        $bad = $this->payForm(26000, ['address' => $this->address(['line1' => '77 Typed Street Kept', 'province' => 'Narnia', 'postal_code' => '12']), 'email' => 'typed.kept@example.test', 'phone' => '0821234567', 'terms' => null]);
        $this->post(route('checkout.place'), $bad)->assertRedirect(route('checkout.show'));

        $this->assertSame(0, Order::count());
        $page = $this->get(route('checkout.show'))->assertOk();
        $page->assertSee('77 Typed Street Kept')->assertSee('typed.kept@example.test')->assertSee('0821234567')->assertSee('Johannesburg')
            ->assertSee('Choose one of the nine provinces.')->assertSee('A South African postal code is four digits.')
            ->assertSee('Please check these before going on');
    }

    public function test_a_price_that_moved_stops_the_order_and_shows_the_new_total_without_losing_the_address(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop();
        $this->addToCart(null, $product);
        $http = $this->fakeHttp();

        $form = $this->payForm(26000, ['address' => $this->address(['line1' => '5 Kept After Drift Road'])]);
        $product->defaultVariant()->first()->update(['price' => 250]);

        $this->post(route('checkout.place'), $form)->assertRedirect(route('checkout.show'))->assertSessionHas('price_changed', ['from' => 26000, 'to' => 31000]);

        $this->assertSame(0, Order::count());
        $http->assertNothingSent();
        $this->get(route('checkout.show'))->assertSee('The price changed while you were checking out')->assertSee('It was R260.00 and is now R310.00')->assertSee('5 Kept After Drift Road');
    }

    public function test_a_rejected_promo_code_keeps_the_input_and_says_why(): void
    {
        [, $product] = $this->shop();
        $this->addToCart(null, $product);

        $this->post(route('checkout.place'), $this->payForm(26000, ['promo_code' => 'GHOST', 'address' => $this->address(['line1' => '8 Promo Kept Street'])]))
            ->assertRedirect(route('checkout.show'));

        $this->assertSame(0, Order::count());
        $this->get(route('checkout.show'))->assertSee('8 Promo Kept Street')->assertSee('GHOST')->assertSee('That promo code is not valid for this order.');
    }

    public function test_a_promo_code_applies_with_its_reason_and_the_total_matches(): void
    {
        $this->enablePaystack();
        [$seller, $product] = $this->shop();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'SAVE20', 'discount_type' => 'fixed', 'discount_value' => 20]);
        $this->addToCart(null, $product);
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $this->post(route('cart.promo'), ['promo_code' => 'SAVE20']);
        $this->post(route('checkout.delivery'), ['email' => 'p@example.test', 'address_id' => 'new', 'address' => $this->address(), 'promo_code' => 'SAVE20']);
        $this->get(route('checkout.show'))->assertSee('SAVE20')->assertSee('R20.00 off, on items from Checkout Stores only')->assertSee('R240.00');

        $this->post(route('checkout.place'), $this->payForm(24000, ['promo_code' => 'SAVE20', 'email' => 'p@example.test']))->assertRedirect('https://checkout.paystack.com/test-access-code');
        $this->assertSame(24000, Order::firstOrFail()->totalCents());
        $this->assertSame(1, PromoCode::where('code', 'SAVE20')->value('used_count'));
    }

    public function test_when_payments_are_not_open_the_page_says_so_and_no_order_is_placed(): void
    {
        config(['emall.checkout.requires_gateway' => true, 'paystack.secret_key' => '']);
        [, $product] = $this->shop();
        $this->addToCart(null, $product);

        $this->get(route('checkout.show'))->assertOk()->assertSee('Payments are not open yet')->assertDontSee('securely</span>', false);

        $this->post(route('checkout.place'), $this->payForm(26000))->assertRedirect(route('checkout.show'))->assertSessionHas('error');
        $this->assertStringContainsString('Payments are not open yet', session('error'));
        $this->assertSame(0, Order::count());
        $this->assertSame(1, app(CartManager::class)->count(null) > 0 ? 1 : 0, 'the cart is kept');
    }

    public function test_a_second_press_with_the_same_token_is_the_same_order(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop();
        $this->addToCart(null, $product);
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        $form = $this->payForm(26000);

        $this->post(route('checkout.place'), $form)->assertRedirect('https://checkout.paystack.com/test-access-code');
        $second = $this->post(route('checkout.place'), $form);

        $this->assertSame(1, Order::count());
        $second->assertRedirect(route('guest.orders.show', Order::first()))->assertSessionHas('status');
        $this->assertStringContainsString('already placed', session('status'));
    }

    public function test_stock_that_ran_out_between_the_cart_and_the_press_sends_the_buyer_to_the_cart_with_the_figure(): void
    {
        $this->enablePaystack();
        [, $product] = $this->shop(['stock' => 5]);
        $this->addToCart(null, $product, 4);
        $product->defaultVariant()->first()->update(['stock' => 2]);

        $this->post(route('checkout.place'), $this->payForm(86000))->assertRedirect(route('cart.index'))->assertSessionHas('error');
        $this->assertStringContainsString('Only 2 of', session('error'));
        $this->assertSame(0, Order::count());
        $this->assertSame(2, $product->defaultVariant()->first()->stock);
    }

    public function test_a_seller_that_does_not_deliver_to_the_address_is_named_before_payment(): void
    {
        $seller = $this->makeSeller(['name' => 'Cape Only Stores']);
        $method = ShippingMethod::factory()->create(['store_id' => $seller->store->id, 'price' => 40]);
        $method->regions()->create(['country' => 'ZA', 'province' => 'Western Cape']);
        $this->addToCart(null, $this->makeListing($seller, ['price' => 100, 'stock' => 5]));

        $this->post(route('checkout.delivery'), ['email' => 'x@example.test', 'address_id' => 'new', 'address' => $this->address()])
            ->assertRedirect(route('checkout.show'))->assertSessionHasErrors('delivery');
        $this->get(route('checkout.show'))->assertSee('Cape Only Stores does not deliver to')->assertSee('Set your delivery details first.');
    }

    public function test_the_fake_gateway_is_never_bound_outside_local_and_testing(): void
    {
        config(['emall.e2e_fake_paystack' => true]);
        $this->assertTrue(CheckoutServiceProvider::fakeGatewayAllowed(), 'allowed under the testing environment with the flag on');

        $this->app['env'] = 'production';
        $this->assertFalse(CheckoutServiceProvider::fakeGatewayAllowed(), 'never in production, whatever the flag says');

        $this->app['env'] = 'testing';
        config(['emall.e2e_fake_paystack' => false]);
        $this->assertFalse(CheckoutServiceProvider::fakeGatewayAllowed(), 'off without the flag');
        $this->assertNotInstanceOf(FakePaystackClient::class, app(PaystackClient::class));
        $this->assertFalse(Route::has('dev.fake-paystack'), 'the fake page route does not exist without the flag');
    }
}
