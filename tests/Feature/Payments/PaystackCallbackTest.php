<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackCallbackTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $seller;

    private $buyer;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
        $listing = $this->makeListing($this->seller, ['price' => 80, 'stock' => 9]);
        $this->order = $this->enablePaystackAndPlace($this->buyer, [[$listing, 2]]);
        $this->payment = $this->startAttempt($this->order);
    }

    private function visitCallback(array $query = [], ?Order $order = null, $as = null)
    {
        return $this->actingAs($as ?? $this->buyer)->get(route('orders.payment.callback', $order ?? $this->order).($query ? '?'.http_build_query($query) : ''));
    }

    public function test_a_verified_success_marks_the_order_paid_once_and_tells_the_seller(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 16000)]);

        $this->visitCallback(['reference' => $this->payment->reference, 'trxref' => $this->payment->reference])
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Payment received'));

        $order = $this->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $payment = $this->payment->fresh();
        $this->assertSame(PaymentAttemptStatus::Success, $payment->status);
        $this->assertSame('4099260516', $payment->provider_transaction_id);
        $this->assertNotNull($payment->paid_at);
        $this->assertCount(1, $this->seller->fresh()->notifications);
    }

    public function test_the_query_string_is_never_trusted(): void
    {
        // Paystack says the attempt was abandoned; the URL claims success.
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 16000, 'abandoned')]);

        $this->visitCallback(['reference' => $this->payment->reference, 'status' => 'success', 'amount' => 16000, 'paid' => 1])
            ->assertRedirect(route('orders.show', $this->order));

        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Pending, $this->payment->fresh()->status);
    }

    public function test_without_a_reference_the_orders_own_latest_attempt_is_verified(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 16000)]);

        $this->visitCallback()->assertRedirect(route('orders.show', $this->order));

        $http->assertSent(fn (Request $r) => str_ends_with($r->url(), '/verify/'.$this->payment->reference));
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
    }

    public function test_a_reference_that_is_not_this_orders_is_ignored_and_never_sent_to_paystack(): void
    {
        $otherListing = $this->makeListing($this->seller, ['price' => 5]);
        $otherOrder = $this->placeOrder($this->makeBuyer(), [[$otherListing, 1]]);
        $other = $this->startAttempt($otherOrder);
        $http = $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse($other->reference, 500)]);

        $this->visitCallback(['reference' => $other->reference])
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('error');

        $http->assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $otherOrder->fresh()->payment_status);
    }

    public function test_amount_currency_and_reference_mismatches_never_mark_the_order_paid(): void
    {
        $cases = [
            'amount' => fn ($ref) => $this->verifyResponse($ref, 15999),
            'currency' => fn ($ref) => $this->verifyResponse($ref, 16000, 'success', ['currency' => 'USD']),
            'reference' => fn ($ref) => $this->verifyResponse('EMALL-999-someoneelse', 16000),
            'overpaid' => fn ($ref) => $this->verifyResponse($ref, 16001),
        ];

        foreach ($cases as $name => $make) {
            $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $make($this->payment->reference)]);

            $this->visitCallback(['reference' => $this->payment->reference])->assertRedirect(route('orders.show', $this->order));

            $this->assertNotSame(PaymentStatus::Paid, $this->order->fresh()->payment_status, "{$name} mismatch must not pay the order");
            $this->assertSame(PaymentAttemptStatus::NeedsReview, $this->payment->fresh()->status, "{$name} mismatch goes to an operator");
            $this->assertTrue(PaymentEvent::where('outcome', 'unmatched')->exists(), "{$name} is recorded");

            // reset for the next case
            $this->payment->forceFill(['status' => PaymentAttemptStatus::Pending])->save();
            PaymentEvent::query()->delete();
        }

        $this->assertCount(0, $this->seller->fresh()->notifications);
    }

    public function test_a_failed_payment_is_recorded_and_can_be_retried(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 16000, 'failed', ['gateway_response' => 'Declined'])]);

        $this->visitCallback(['reference' => $this->payment->reference])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not completed'));

        $this->assertSame(PaymentStatus::Failed, $this->order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Failed, $this->payment->fresh()->status);

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse(url: 'https://checkout.paystack.com/second')]);
        $this->actingAs($this->buyer)->post(route('orders.pay', $this->order))->assertRedirect('https://checkout.paystack.com/second');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_a_paystack_outage_during_the_callback_leaves_the_order_pending(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('boom')]);

        $this->visitCallback(['reference' => $this->payment->reference])
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not confirm'));

        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Pending, $this->payment->fresh()->status);
    }

    public function test_replaying_the_callback_after_payment_changes_nothing_and_does_not_call_paystack(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($this->payment->reference, 16000)]);
        $this->visitCallback(['reference' => $this->payment->reference]);
        $paidAt = $this->order->fresh()->paid_at;

        $http = $this->fakeHttp();
        $this->visitCallback(['reference' => $this->payment->reference])->assertRedirect(route('orders.show', $this->order));

        $http->assertNothingSent();
        $this->assertEquals($paidAt, $this->order->fresh()->paid_at);
        $this->assertCount(1, $this->seller->fresh()->notifications);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_another_buyer_the_seller_and_guests_cannot_confirm_my_order(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse($this->payment->reference, 16000)]);

        $this->get(route('orders.payment.callback', $this->order))->assertRedirect(route('login'));
        $this->visitCallback(['reference' => $this->payment->reference], null, $this->makeBuyer())->assertNotFound();
        $this->visitCallback(['reference' => $this->payment->reference], null, $this->seller)->assertNotFound();

        $http->assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
    }

    public function test_when_no_key_is_configured_the_callback_does_nothing_and_says_so(): void
    {
        config(['paystack.secret_key' => '']);
        $http = $this->fakeHttp();

        $this->visitCallback(['reference' => $this->payment->reference])->assertRedirect(route('orders.show', $this->order));

        $http->assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
    }
}
