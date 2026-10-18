<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackCheckoutTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function cartFor($buyer, $product, int $qty = 1): void
    {
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => $qty]);
    }

    private function submit($buyer, ?string $token = null, array $extra = [])
    {
        return $this->actingAs($buyer)->post(route('cart.checkout'), array_merge([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'shipping_address' => '1 Main St',
        ], $extra));
    }

    public function test_without_a_paystack_key_checkout_behaves_exactly_as_before(): void
    {
        config(['paystack.secret_key' => '']);
        $http = $this->fakeHttp();
        $buyer = $this->makeBuyer();
        $this->cartFor($buyer, $this->makeListing($this->makeSeller(), ['price' => 25]));

        $response = $this->submit($buyer);

        $order = Order::firstOrFail();
        $response->assertRedirect(route('orders.show', $order));
        $response->assertSessionHas('status', fn ($m) => str_contains($m, 'pending and unpaid'));
        $this->assertFalse($order->requires_payment);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('payments', 0);
        $http->assertNothingSent();
    }

    public function test_with_a_key_checkout_creates_the_order_then_sends_the_buyer_to_paystack(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/abc-xyz-123')]);
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 123.45, 'stock' => 5]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TENOFF', 'discount_type' => 'fixed', 'discount_value' => 10]);
        $buyer = $this->makeBuyer();
        $this->cartFor($buyer, $product, 2);

        $response = $this->submit($buyer, null, ['promo_code' => 'tenoff']);

        $order = Order::firstOrFail();
        $response->assertRedirect('https://checkout.paystack.com/abc-xyz-123');

        // 2 x 123.45 = 246.90, less R10.00 promo = 236.90 -> 23690 cents
        $this->assertSame(23690, Money::toCents($order->total));
        $payment = Payment::firstOrFail();
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame('paystack', $payment->provider);
        $this->assertSame(23690, $payment->amount_cents);
        $this->assertSame('ZAR', $payment->currency);
        $this->assertSame(PaymentAttemptStatus::Pending, $payment->status);
        $this->assertMatchesRegularExpression('/^EMALL-'.$order->id.'-[a-z0-9]{12,}$/', $payment->reference);
        $this->assertTrue($order->fresh()->requires_payment);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        $http->assertSent(function (Request $r) use ($order, $payment, $buyer) {
            return $r->url() === 'https://api.paystack.co/transaction/initialize'
                && $r->data() === [
                    'email' => $buyer->email,
                    'amount' => 23690,
                    'currency' => 'ZAR',
                    'reference' => $payment->reference,
                    'callback_url' => route('orders.payment.callback', $order),
                    'metadata' => json_encode(['order_id' => $order->id, 'order_reference' => $order->reference()]),
                ];
        });
        $this->assertSame(3, $product->fresh()->stock, 'stock is held by the order while it awaits payment');
    }

    public function test_sellers_are_not_told_about_an_order_until_it_is_paid(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->cartFor($buyer, $this->makeListing($seller));

        $this->submit($buyer);

        $this->assertCount(0, $seller->fresh()->notifications, 'an unpaid order is nothing to fulfil yet');
    }

    public function test_a_paystack_outage_leaves_a_saved_unpaid_order_with_a_retry_path(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('timeout')]);
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 40]);
        $this->cartFor($buyer, $product);

        $response = $this->submit($buyer);

        $order = Order::firstOrFail();
        $response->assertRedirect(route('orders.show', $order));
        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'Pay now'));
        $this->assertTrue($order->requires_payment);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertDatabaseCount('payments', 0);

        // the retry path: the order page offers Pay now, and it works once Paystack is back
        $this->actingAs($buyer)->get(route('orders.show', $order))->assertSee('Pay now');

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/retry')]);
        $this->actingAs($buyer)->post(route('orders.pay', $order))->assertRedirect('https://checkout.paystack.com/retry');
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_a_refused_live_key_stops_checkout_before_any_order_exists(): void
    {
        $this->enablePaystack('sk_live_not_allowed_here_000');
        $http = $this->fakeHttp();
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        $this->cartFor($buyer, $product);

        $this->submit($buyer)->assertRedirect(route('cart.index'))->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
        $http->assertNothingSent();
    }

    public function test_replaying_the_checkout_token_neither_duplicates_the_order_nor_re_initializes(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        $buyer = $this->makeBuyer();
        $this->cartFor($buyer, $this->makeListing($this->makeSeller()));
        $token = (string) Str::uuid();

        $this->submit($buyer, $token);
        $second = $this->submit($buyer, $token);

        $order = Order::firstOrFail();
        $second->assertRedirect(route('orders.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $http->assertSentCount(1);
    }

    public function test_a_fully_discounted_order_needs_no_charge_and_never_calls_paystack(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'FREEBIE', 'discount_type' => 'percent', 'discount_value' => 100]);
        $this->cartFor($buyer, $this->makeListing($seller, ['price' => 30]));

        $this->submit($buyer, null, ['promo_code' => 'FREEBIE'])->assertRedirect(route('orders.show', Order::firstOrFail()));

        $order = Order::firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $http->assertNothingSent();
        $this->assertCount(1, $seller->fresh()->notifications, 'a free order is ready to fulfil, so the seller is told');
    }

    public function test_the_cart_page_says_what_happens_next(): void
    {
        $this->enablePaystack();
        $buyer = $this->makeBuyer();
        $this->cartFor($buyer, $this->makeListing($this->makeSeller()));

        $this->actingAs($buyer)->get(route('cart.index'))
            ->assertSee('Pay with Paystack')
            ->assertSee('TEST MODE')
            ->assertDontSee('No payment is taken');

        config(['paystack.secret_key' => '']);
        $this->actingAs($buyer)->get(route('cart.index'))
            ->assertSee('No payment is taken')
            ->assertDontSee('TEST MODE');
    }
}
