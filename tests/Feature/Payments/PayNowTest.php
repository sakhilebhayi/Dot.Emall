<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PayNowTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $seller;

    private $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
    }

    private function unpaidOrder(array $prices = [60]): Order
    {
        $lines = array_map(fn ($p) => [$this->makeListing($this->seller, ['price' => $p, 'stock' => 20]), 1], $prices);

        return $this->enablePaystackAndPlace($this->buyer, $lines);
    }

    public function test_pay_now_starts_an_attempt_and_redirects_to_paystack(): void
    {
        $order = $this->unpaidOrder();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/one')]);

        $this->actingAs($this->buyer)->post(route('orders.pay', $order))->assertRedirect('https://checkout.paystack.com/one');

        $http->assertSentCount(1);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_pressing_pay_now_again_reuses_the_live_attempt_after_checking_it_was_not_paid(): void
    {
        $order = $this->unpaidOrder();
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/one')]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $order));
        $attempt = Payment::firstOrFail();

        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, 6000, 'abandoned')]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $order))->assertRedirect('https://checkout.paystack.com/one');

        $this->assertDatabaseCount('payments', 1);
        $http->assertSent(fn (Request $r) => str_contains($r->url(), '/transaction/verify/'));
        $http->assertNotSent(fn (Request $r) => str_contains($r->url(), '/initialize'));
    }

    public function test_pay_now_after_an_actual_payment_marks_it_paid_instead_of_charging_twice(): void
    {
        $order = $this->unpaidOrder();
        $attempt = $this->startAttempt($order);

        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, 6000)]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $order))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $http->assertNotSent(fn (Request $r) => str_contains($r->url(), '/initialize'));
    }

    public function test_an_expired_attempt_is_not_reused(): void
    {
        $order = $this->unpaidOrder();
        $old = $this->startAttempt($order);
        $this->travel(31)->minutes();

        $this->fakeHttp([
            'api.paystack.co/transaction/verify/*' => $this->verifyResponse($old->reference, 6000, 'abandoned'),
            'api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/fresh'),
        ]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $order))->assertRedirect('https://checkout.paystack.com/fresh');

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(PaymentAttemptStatus::Abandoned, $old->fresh()->status);
    }

    public function test_when_the_order_total_changed_the_old_attempt_is_replaced_with_the_new_amount(): void
    {
        $order = $this->unpaidOrder([60, 40]);
        $old = $this->startAttempt($order);
        $this->assertSame(10000, $old->amount_cents);
        $cheaper = $order->items->firstWhere('total', '40.00') ?? $order->items->last();
        app(OrderLifecycle::class)->cancelItem($cheaper);

        $http = $this->fakeHttp([
            'api.paystack.co/transaction/verify/*' => $this->verifyResponse($old->reference, 10000, 'abandoned'),
            'api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/smaller'),
        ]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $order))->assertRedirect('https://checkout.paystack.com/smaller');

        $http->assertSent(fn (Request $r) => str_contains($r->url(), '/initialize') && $r->data()['amount'] === 6000);
        $this->assertSame(PaymentAttemptStatus::Abandoned, $old->fresh()->status);
    }

    public function test_orders_that_cannot_be_paid_are_refused_without_calling_paystack(): void
    {
        $paid = $this->unpaidOrder();
        $this->payOrder($paid);

        $cancelled = $this->unpaidOrder();
        foreach ($cancelled->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line);
        }

        config(['paystack.secret_key' => '']);
        $legacy = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller), 1]]);

        $http = $this->fakeHttp();
        config(['paystack.secret_key' => $this->testKey]);

        foreach ([$paid, $cancelled, $legacy] as $order) {
            $this->actingAs($this->buyer)->post(route('orders.pay', $order))
                ->assertRedirect(route('orders.show', $order))
                ->assertSessionHas('error');
        }

        $http->assertNothingSent();
    }

    public function test_another_user_cannot_pay_for_or_even_probe_my_order(): void
    {
        $order = $this->unpaidOrder();
        $http = $this->fakeHttp();

        $this->post(route('orders.pay', $order))->assertRedirect(route('login'));
        $this->actingAs($this->makeBuyer())->post(route('orders.pay', $order))->assertNotFound();
        $this->actingAs($this->seller)->post(route('orders.pay', $order))->assertNotFound();

        $http->assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_order_page_offers_pay_now_only_while_it_can_be_paid_and_shows_test_mode(): void
    {
        $order = $this->unpaidOrder();

        $this->actingAs($this->buyer)->get(route('orders.show', $order))
            ->assertSee('Pay now')
            ->assertSee('TEST MODE')
            ->assertSee('Awaiting payment');

        $this->payOrder($order);

        $this->actingAs($this->buyer)->get(route('orders.show', $order))
            ->assertDontSee('Pay now')
            ->assertDontSee('Awaiting payment')
            ->assertSee('Payment received');
    }

    public function test_no_test_mode_badge_when_live_payments_are_deliberately_enabled(): void
    {
        $this->enablePaystack('sk_live_deliberate_0000', ['paystack.allow_live' => true]);
        $order = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller), 1]]);

        $this->actingAs($this->buyer)->get(route('orders.show', $order))
            ->assertSee('Pay now')
            ->assertDontSee('TEST MODE');
    }

    public function test_a_legacy_unpaid_order_shows_the_old_honest_text_and_no_pay_button(): void
    {
        config(['paystack.secret_key' => '']);
        $order = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller), 1]]);

        $this->actingAs($this->buyer)->get(route('orders.show', $order))
            ->assertSee('pending and unpaid')
            ->assertDontSee('Pay now')
            ->assertDontSee('TEST MODE');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
