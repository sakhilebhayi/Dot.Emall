<?php

namespace Tests\Feature\Payments;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Paystack charges in the storefront currency: PAYSTACK_CURRENCY is only an
 * optional echo of it, and a configuration where the two differ is refused
 * before an order is placed. Each payment is for the ORDER's own currency.
 */
class PaystackCurrencyTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function submit($buyer)
    {
        return $this->actingAs($buyer)->post(route('cart.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St',
        ]);
    }

    public function test_paystack_charges_in_the_storefront_currency_when_nothing_else_is_set(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'EUR']);

        $this->assertSame('EUR', app(PaystackConfig::class)->currency());
        $this->assertNull(app(PaystackConfig::class)->problem());
    }

    public function test_the_example_env_gives_paystack_no_currency_of_its_own(): void
    {
        $set = preg_grep('/^\s*PAYSTACK_CURRENCY\s*=\s*\S/', preg_split('/\R/', file_get_contents(base_path('.env.example'))));

        $this->assertSame([], array_values($set), 'PAYSTACK_CURRENCY must default to SHOP_CURRENCY, not to a second copy of ZAR');
        $this->assertStringContainsString('SHOP_CURRENCY=ZAR', file_get_contents(base_path('.env.example')));
    }

    public function test_an_explicit_paystack_currency_that_agrees_is_fine(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => 'ZAR']);
        config(['shop.currency' => 'ZAR']);

        $this->assertSame('ZAR', app(PaystackConfig::class)->currency());
        $this->assertNull(app(PaystackConfig::class)->problem());
        $this->assertTrue(app(PaystackConfig::class)->isUsable());
    }

    public function test_a_paystack_currency_that_disagrees_with_the_storefront_is_refused_with_a_clear_message(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => 'USD']);
        config(['shop.currency' => 'ZAR']);
        $config = app(PaystackConfig::class);

        $this->assertTrue($config->isMisconfigured());
        $this->assertFalse($config->isUsable());
        $this->assertStringContainsString('PAYSTACK_CURRENCY (USD)', $config->problem());
        $this->assertStringContainsString('SHOP_CURRENCY (ZAR)', $config->problem());

        try {
            $config->secretKey();
            $this->fail('a mismatched currency must not hand out the key');
        } catch (PaymentGatewayException $e) {
            $this->assertStringContainsString('SHOP_CURRENCY', $e->getMessage());
        }
    }

    public function test_a_mismatch_means_nothing_without_a_key(): void
    {
        config(['paystack.secret_key' => '', 'paystack.currency' => 'USD', 'shop.currency' => 'ZAR']);

        $this->assertFalse(app(PaystackConfig::class)->isMisconfigured());
        $this->assertNull(app(PaystackConfig::class)->problem());
    }

    public function test_checkout_with_a_mismatched_currency_places_no_order_and_sends_nothing(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => 'USD']);
        config(['shop.currency' => 'ZAR']);
        $http = $this->fakeHttp();
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 25, 'stock' => 5]);
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 2]);

        $this->submit($buyer)->assertRedirect(route('cart.index'))->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock, 'no stock is held for a payment that cannot be taken');
        $this->assertDatabaseCount('payments', 0);
        $http->assertNothingSent();
    }

    public function test_the_operator_desk_says_why_card_payments_are_refused(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => 'USD']);
        config(['shop.currency' => 'ZAR']);
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $page = $this->actingAs($operator)->get(route('operator.payments.index'))->assertOk();

        $page->assertSee('Refused')->assertSee('PAYSTACK_CURRENCY (USD)')->assertSee('SHOP_CURRENCY (ZAR)');
    }

    public function test_the_payment_attempt_is_for_the_orders_own_currency_and_amount(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'EUR']);
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['price' => 80]), 2]]);
        $this->assertSame('EUR', $order->currency);

        $payment = app(PaymentService::class)->startPayment($order);

        $this->assertSame('EUR', $payment->currency);
        $this->assertSame(16000, $payment->amount_cents);
        $http->assertSent(fn (Request $r) => $r->data()['currency'] === 'EUR' && $r->data()['amount'] === 16000);
    }

    public function test_an_order_in_another_currency_than_the_gateway_now_charges_cannot_be_paid(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'ZAR']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller(), ['price' => 80]), 1]]);

        // The operator later moves the whole shop to euro.
        config(['shop.currency' => 'EUR']);
        $http = $this->fakeHttp();

        $response = $this->actingAs($buyer)->post(route('orders.pay', $order))->assertRedirect(route('orders.show', $order));

        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'ZAR') && str_contains($m, 'EUR'));
        $http->assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_service_refuses_a_cross_currency_payment_with_a_gateway_exception(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['price' => 80]), 1]]);
        config(['shop.currency' => 'GBP']);
        $this->fakeHttp();

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('ZAR');

        app(PaymentService::class)->startPayment($order);
    }

    public function test_a_confirmation_in_a_different_currency_than_the_orders_is_not_accepted(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'EUR']);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['price' => 80]), 1]]);
        $payment = $this->startAttempt($order);

        $outcome = app(PaymentService::class)->recordSuccessfulCharge($payment->reference, $payment->amount_cents, 'ZAR', '991', [], 'webhook');

        $this->assertSame(PaymentService::UNMATCHED, $outcome);
        $this->assertNotSame('paid', $order->fresh()->payment_status->value);

        $ok = app(PaymentService::class)->recordSuccessfulCharge($payment->reference, $payment->amount_cents, 'EUR', '992', [], 'webhook');
        $this->assertSame(PaymentService::APPLIED, $ok);
    }

    public function test_a_free_order_records_a_zero_payment_in_the_orders_currency(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'EUR']);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['price' => 80]), 1]]);
        $order->forceFill(['total' => 0])->save();

        $payment = app(PaymentService::class)->startPayment($order);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame('EUR', $payment->currency);
    }

    public function test_the_pay_panel_and_cart_name_the_currency_in_the_storefront_format(): void
    {
        $this->enablePaystack(extra: ['paystack.currency' => null]);
        config(['shop.currency' => 'ZAR']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller(), ['price' => 236.9]), 1]]);

        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertSee('R236.90')->assertDontSee('$236.90');
        $this->assertInstanceOf(Order::class, $order);
    }
}
