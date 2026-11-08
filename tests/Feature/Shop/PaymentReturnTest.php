<?php

namespace Tests\Feature\Shop;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/** What the buyer sees on coming back from Paystack: the state comes from Paystack's answer to our server, never from the address. */
class PaymentReturnTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $buyer;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $seller = $this->makeSeller(['name' => 'Return Stores']);
        $this->buyer = $this->makeBuyer();
        $this->order = $this->enablePaystackAndPlace($this->buyer, [[$this->makeListing($seller, ['price' => 80, 'stock' => 9]), 1]]);
        $this->payment = $this->startAttempt($this->order);
    }

    private function back(string $status, array $query = [])
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 8000, $status)]);

        return $this->actingAs($this->buyer)->followingRedirects()->get(route('orders.payment.callback', $this->order).'?'.http_build_query($query + ['reference' => $this->payment->reference]));
    }

    public function test_a_confirmed_payment_is_told_as_paid_with_the_seller_informed(): void
    {
        $this->back('success')->assertOk()->assertSee('Payment received. Your order is confirmed.')->assertSee('The seller has been told and will pack your order')->assertDontSee('Check payment again');
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
    }

    public function test_a_failed_attempt_says_so_and_offers_a_retry_on_any_method(): void
    {
        $this->back('failed')->assertSee('Your payment was not completed')->assertSee('Try payment again')->assertSee('choose a different payment method on Paystack');
        $this->assertSame(PaymentStatus::Failed, $this->order->fresh()->payment_status);
    }

    public function test_an_abandoned_attempt_is_not_paid_and_the_hold_time_is_stated(): void
    {
        $this->back('abandoned')->assertSee('Your payment was not completed')->assertSee('Pay by')->assertSee('the items go back on sale');
        $this->assertFalse($this->order->fresh()->payment_status->isSettled());
    }

    public function test_the_address_cannot_say_paid(): void
    {
        $this->back('abandoned', ['status' => 'success', 'paid' => '1', 'trxref' => $this->payment->reference]);

        $this->assertFalse($this->order->fresh()->payment_status->isSettled(), 'only Paystack verification to our server marks an order paid');
    }

    public function test_when_paystack_cannot_be_reached_the_page_says_so_without_guessing(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => fn () => throw new ConnectionException('down')]);

        $this->actingAs($this->buyer)->followingRedirects()->get(route('orders.payment.callback', $this->order).'?reference='.$this->payment->reference)
            ->assertSee('We could not confirm your payment just now')->assertSee('Check payment again')->assertSee('Please do not pay twice');
        $this->assertFalse($this->order->fresh()->payment_status->isSettled());
    }

    public function test_check_payment_again_asks_paystack_and_updates_the_order(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 8000, 'success')]);

        $this->actingAs($this->buyer)->followingRedirects()->post(route('orders.payment.check', $this->order))->assertSee('Payment received');
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
    }

    public function test_pay_now_sends_the_buyer_on_to_the_open_attempt(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/*' => fn ($r) => str_contains($r->url(), 'verify') ? $this->verifyResponse($this->payment->reference, 8000, 'abandoned') : $this->initializeResponse()($r)]);

        $this->actingAs($this->buyer)->post(route('orders.pay', $this->order))->assertRedirect();
    }

    public function test_a_guest_comes_back_on_the_tokened_link_and_sees_the_same_truth(): void
    {
        $seller = $this->makeSeller();
        $this->cartAndPlaceAsGuest($seller, $order, $payment);
        $token = app(GuestOrderAccess::class)->tokenFor($order);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 8000, 'success')]);

        $this->followingRedirects()->get(route('guest.orders.payment.callback', ['order' => $order->id, 'token' => $token]).'?reference='.$payment->reference)
            ->assertOk()->assertSee('Payment received. Your order is confirmed.')->assertSee('A confirmation is on its way to');
        $this->assertTrue($order->fresh()->payment_status->isSettled());

        $this->get(route('guest.orders.payment.callback', ['order' => $order->id, 'token' => str_repeat('b', 43)]))->assertNotFound();
    }

    public function test_an_order_that_expired_unpaid_says_it_was_cancelled_and_nothing_is_due(): void
    {
        app(\App\Services\OrderLifecycle::class)->cancelUnpaidOrder($this->order->fresh());

        $this->actingAs($this->buyer)->get(route('orders.show', $this->order))->assertSee('This order was cancelled. Nothing is due.')->assertDontSee('Try payment again');
    }

    private function cartAndPlaceAsGuest($seller, &$order, &$payment): void
    {
        $product = $this->makeListing($seller, ['price' => 80, 'stock' => 9]);
        $variant = $product->defaultVariant()->first();
        $order = app(\App\Services\CheckoutService::class)->placeAsGuest('g@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => '1 Test Street, Cape Town', 'shipping_name' => 'G'], (string) \Illuminate\Support\Str::uuid());
        $payment = $this->startAttempt($order);
    }
}
