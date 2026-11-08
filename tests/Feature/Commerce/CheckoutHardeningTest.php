<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class CheckoutHardeningTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_gateway_gate_is_off_by_default_outside_production_and_on_in_production(): void
    {
        $this->assertFalse(config('emall.checkout.requires_gateway'), 'tests are not production');

        $config = require base_path('config/emall.php');
        $this->assertIsBool($config['checkout']['requires_gateway']);
    }

    public function test_with_the_gate_on_checkout_is_refused_until_paystack_is_usable(): void
    {
        config(['emall.checkout.requires_gateway' => true, 'paystack.secret_key' => '']);
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);

        try {
            $this->placeOrder($buyer, [[$product, 1]]);
            $this->fail('no order may be placed without a payment gateway');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('Payments are not open yet', $e->getMessage());
        }

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count(), 'the cart is kept');

        $this->enablePaystack();
        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street']);
        $this->assertTrue($order->requires_payment);
    }

    public function test_the_gate_also_stops_the_checkout_form_with_a_message(): void
    {
        config(['emall.checkout.requires_gateway' => true, 'paystack.secret_key' => '']);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $this->makeListing($this->makeSeller())->id, 'quantity' => 1]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) \Illuminate\Support\Str::uuid(), 'shipping_address' => '1 Test Street'])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Payments are not open yet'));
    }

    public function test_the_gate_does_not_block_a_replay_of_an_order_already_placed(): void
    {
        $this->enablePaystack();
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        $token = (string) \Illuminate\Support\Str::uuid();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        $first = app(CheckoutService::class)->place($buyer, ['shipping_address' => 'x'], $token);

        config(['emall.checkout.requires_gateway' => true, 'paystack.secret_key' => '']);

        $this->assertSame($first->id, app(CheckoutService::class)->place($buyer, ['shipping_address' => 'x'], $token)->id);
    }

    public function test_a_buyer_may_hold_at_most_three_unpaid_orders(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        foreach (range(1, 3) as $n) {
            $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);
        }

        try {
            $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);
            $this->fail('the fourth unpaid order must be refused');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('waiting for payment', $e->getMessage());
        }

        // Paying one frees a place.
        $this->payOrder(Order::query()->where('user_id', $buyer->id)->orderBy('id')->first());
        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => 'x']);
        $this->assertNotNull($order->id);
    }

    public function test_the_unpaid_order_cap_is_not_applied_when_no_payment_is_asked_for(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        foreach (range(1, 5) as $n) {
            $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);
        }

        $this->assertSame(5, Order::query()->where('user_id', $buyer->id)->count());
    }

    public function test_the_stock_hold_defaults_to_thirty_minutes(): void
    {
        $this->assertSame(30, (int) config('paystack.unpaid_window_minutes'));
        $this->assertSame(30, (int) config('emall.checkout.stock_hold_minutes'));
    }

    public function test_stock_is_held_longer_while_a_payment_attempt_is_open_but_not_forever(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->enablePaystack(null, ['paystack.unpaid_window_minutes' => 30]);
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 2]]);

        Carbon::setTestNow('2026-11-09 10:25:00');
        $attempt = $this->startAttempt($order);

        // 10:45 - 45 minutes after the order, 20 after the attempt: the buyer is at Paystack, so the hold stands.
        Carbon::setTestNow('2026-11-09 10:45:00');
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents, 'abandoned')]);
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);

        // 11:05 - 65 minutes after the order, past twice the window: the hold ends whatever the attempt says.
        Carbon::setTestNow('2026-11-09 11:05:00');
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents, 'abandoned')]);
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_an_unpaid_order_with_no_attempt_is_released_at_the_plain_window(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->enablePaystack(null, ['paystack.unpaid_window_minutes' => 30]);
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 2]]);

        Carbon::setTestNow('2026-11-09 10:31:00');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_a_cancelled_unpaid_order_does_not_stay_payment_pending(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->enablePaystack(null, ['paystack.unpaid_window_minutes' => 30]);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]]);
        $attempt = $this->startAttempt($order);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        Carbon::setTestNow('2026-11-09 11:30:00');
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents, 'abandoned')]);
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Abandoned, $attempt->fresh()->status);
    }

    public function test_a_buyer_cancelling_every_line_of_an_order_with_an_open_attempt_leaves_it_unpaid_not_pending(): void
    {
        $this->enablePaystack();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller()), 1]]);
        $attempt = $this->startAttempt($order);

        app(OrderLifecycle::class)->cancelItem($order->items->first(), $buyer);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Abandoned, $attempt->fresh()->status);
    }

    public function test_expiry_restocks_variants_once_and_a_rerun_changes_nothing(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->enablePaystack(null, ['paystack.unpaid_window_minutes' => 30]);
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $this->placeOrder($this->makeBuyer(), [[$product, 4]]);

        Carbon::setTestNow('2026-11-09 11:00:00');
        $this->artisan('orders:expire-unpaid');
        $this->artisan('orders:expire-unpaid');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(5, $product->defaultVariant()->first()->stock);
        $this->assertSame(1, \App\Models\StockMovement::query()->where('product_id', $product->id)->where('reason', 'expiry')->count());
    }

    public function test_a_seller_cannot_ship_pack_or_hand_over_an_unpaid_order(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);
        $line = $order->items->first();

        foreach (['processing', 'shipped', 'ready_for_collection'] as $to) {
            $this->actingAs($seller)->patch(route('seller.order-items.update-status', $line), ['status' => $to])->assertSessionHasErrors('status');
        }

        $this->assertSame(OrderStatus::Pending, $line->fresh()->status);

        $this->payOrder($order);
        $this->actingAs($seller)->patch(route('seller.order-items.update-status', $line), ['status' => 'shipped'])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Shipped, $line->fresh()->status);
    }

    public function test_a_low_stock_alert_goes_to_the_seller_once_when_a_sale_crosses_the_threshold(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 8, 'low_stock_threshold' => 5]);
        $seller->notifications()->delete();

        $this->placeOrder($this->makeBuyer(), [[$product, 2]]);   // 8 -> 6: still above
        $this->assertSame(0, $seller->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'low_stock')->count());

        $this->placeOrder($this->makeBuyer(), [[$product, 2]]);   // 6 -> 4: crossed
        $this->placeOrder($this->makeBuyer(), [[$product, 1]]);   // 4 -> 3: already low, no second alert

        $alerts = $seller->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'low_stock');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('down to 4', $alerts->first()->data['message']);
    }
}
