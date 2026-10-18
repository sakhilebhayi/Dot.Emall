<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $seller;

    private $buyer;

    private $listing;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
        $this->listing = $this->makeListing($this->seller, ['price' => 80, 'stock' => 9]);
        $this->order = $this->enablePaystackAndPlace($this->buyer, [[$this->listing, 2]]);
        $this->payment = $this->startAttempt($this->order);
    }

    // ---- signature matrix ------------------------------------------------

    public function test_a_correctly_signed_charge_success_marks_the_order_paid(): void
    {
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Success, $this->payment->fresh()->status);
        $this->assertSame('4099260516', $this->payment->fresh()->provider_transaction_id);
    }

    public function test_every_bad_signature_gets_the_same_generic_401_and_changes_nothing(): void
    {
        $body = json_encode($this->chargeSuccessPayload($this->payment));
        $valid = $this->sign($body);

        $cases = [
            'missing header' => fn () => $this->postWebhook($body, null, false),
            'empty header' => fn () => $this->postWebhook($body, ''),
            'garbage' => fn () => $this->postWebhook($body, 'not-a-signature'),
            'truncated' => fn () => $this->postWebhook($body, substr($valid, 0, 64)),
            'one char off' => fn () => $this->postWebhook($body, substr($valid, 0, -1).($valid[-1] === 'a' ? 'b' : 'a')),
            'signed with another key' => fn () => $this->postWebhook($body, $this->sign($body, 'sk_test_some_other_key_9999')),
            'sha256 instead of sha512' => fn () => $this->postWebhook($body, hash_hmac('sha256', $body, $this->testKey)),
            'signature of a different body' => fn () => $this->postWebhook($body, $this->sign($body.' ')),
            'body tampered after signing' => fn () => $this->postWebhook(str_replace('"amount":16000', '"amount":1', $body), $valid),
            'plain hash, no hmac' => fn () => $this->postWebhook($body, hash('sha512', $body.$this->testKey)),
        ];

        $bodies = [];
        foreach ($cases as $name => $send) {
            $response = $send();
            $response->assertStatus(401);
            $bodies[$name] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'the 401 body must not reveal why it failed');
        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_the_signature_is_checked_before_the_body_is_parsed(): void
    {
        $this->postWebhook('this is { not json', 'wrong')->assertStatus(401);
        $this->postWebhook('this is { not json')->assertStatus(400);
        $this->postWebhook('[1,2,3]')->assertStatus(400);
        $this->postWebhook('{"no":"event"}')->assertStatus(400);
    }

    public function test_an_uppercase_hex_signature_of_the_right_value_is_accepted(): void
    {
        $body = json_encode($this->chargeSuccessPayload($this->payment));

        $this->postWebhook($body, strtoupper($this->sign($body)))->assertOk();
    }

    public function test_with_no_key_configured_or_a_refused_live_key_every_webhook_is_refused(): void
    {
        $body = json_encode($this->chargeSuccessPayload($this->payment));

        config(['paystack.secret_key' => '']);
        $this->postWebhook($body, $this->sign($body, ''))->assertStatus(401);

        $this->enablePaystack('sk_live_refused_key_0000');
        $this->postWebhook($body, $this->sign($body, 'sk_live_refused_key_0000'))->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
    }

    public function test_the_route_is_public_throttled_and_outside_csrf(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.paystack');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/paystack', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:webhooks', $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);

        $csrf = app(PreventRequestForgery::class);
        $this->assertContains('webhooks/paystack', $csrf->getExcludedPaths());
    }

    // ---- exactly-once ----------------------------------------------------

    public function test_a_duplicate_webhook_is_acknowledged_but_applies_nothing_twice(): void
    {
        Event::fake([OrderPaid::class]);

        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();
        $paidAt = $this->order->fresh()->paid_at;
        $this->travel(5)->minutes();
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertEquals($paidAt, $this->order->fresh()->paid_at);
        $this->assertSame(1, PaymentEvent::where('outcome', 'applied')->count());
        $this->assertSame(1, PaymentEvent::where('outcome', 'duplicate')->count());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_the_seller_is_notified_exactly_once_whatever_the_order_of_callback_and_webhook(): void
    {
        $service = app(PaymentService::class);

        // webhook first, then a callback-style confirmation of the same charge
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();
        $outcome = $service->recordSuccessfulCharge($this->payment->reference, 16000, 'ZAR', '4099260516', [], 'callback');

        $this->assertSame('duplicate', $outcome);
        $this->assertCount(1, $this->seller->fresh()->notifications);
    }

    public function test_a_stale_in_memory_attempt_cannot_double_apply_a_charge(): void
    {
        $stale = Payment::findOrFail($this->payment->id); // loaded while still pending
        $service = app(PaymentService::class);

        $service->recordSuccessfulCharge($this->payment->reference, 16000, 'ZAR', '4099260516', [], 'callback');
        $this->assertSame(PaymentAttemptStatus::Pending, $stale->status, 'the stale copy still believes it is pending');

        $second = $service->recordSuccessfulCharge($stale->reference, 16000, 'ZAR', '4099260516', [], 'webhook');

        $this->assertSame('duplicate', $second);
        $this->assertCount(1, $this->seller->fresh()->notifications);
        $this->assertSame(1, Payment::where('status', PaymentAttemptStatus::Success->value)->count());
    }

    public function test_a_transaction_id_can_belong_to_only_one_payment(): void
    {
        $other = $this->startAttemptForNewOrder();
        $service = app(PaymentService::class);

        $service->recordSuccessfulCharge($this->payment->reference, 16000, 'ZAR', '777', [], 'webhook');
        $outcome = $service->recordSuccessfulCharge($other->reference, $other->amount_cents, 'ZAR', '777', [], 'webhook');

        $this->assertSame('needs_review', $outcome);
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
        $this->assertNotSame(PaymentStatus::Paid, $other->order->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::NeedsReview, $other->fresh()->status);
    }

    private function startAttemptForNewOrder(): Payment
    {
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->seller, ['price' => 10]), 1]]);

        return $this->startAttempt($order);
    }

    // ---- mismatches ------------------------------------------------------

    public function test_amount_currency_and_unknown_reference_record_an_unmatched_event_and_still_return_200(): void
    {
        $cases = [
            'amount' => $this->chargeSuccessPayload($this->payment, ['amount' => 15999]),
            'currency' => $this->chargeSuccessPayload($this->payment, ['currency' => 'USD']),
            'unknown reference' => $this->chargeSuccessPayload($this->payment, ['reference' => 'SOMEONE-ELSES-ORDER']),
        ];

        foreach ($cases as $name => $payload) {
            $this->postWebhook($payload)->assertOk();
            $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status, $name);
        }

        $this->assertSame(3, PaymentEvent::where('outcome', 'unmatched')->count());
        $this->assertCount(0, $this->seller->fresh()->notifications);
    }

    public function test_a_charge_success_whose_status_is_not_success_is_not_applied(): void
    {
        $this->postWebhook($this->chargeSuccessPayload($this->payment, ['status' => 'failed']))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
    }

    public function test_unrelated_events_are_acknowledged_and_ignored(): void
    {
        $this->postWebhook(['event' => 'transfer.success', 'data' => ['reference' => $this->payment->reference]])->assertOk();
        $this->postWebhook(['event' => 'charge.dispute.create', 'data' => []])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->order->fresh()->payment_status);
    }

    // ---- cancelled-then-paid and double payment ---------------------------

    public function test_a_payment_after_the_order_was_cancelled_goes_to_review_and_does_not_reopen_it(): void
    {
        foreach ($this->order->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line);
        }
        $this->assertSame(9, $this->listing->fresh()->stock);

        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(9, $this->listing->fresh()->stock, 'stock is not taken again');
        $payment = $this->payment->fresh();
        $this->assertSame(PaymentAttemptStatus::NeedsReview, $payment->status);
        $this->assertSame('4099260516', $payment->provider_transaction_id, 'we still record that money arrived');
        $this->assertTrue(PaymentEvent::where('outcome', 'needs_review')->where('payment_id', $payment->id)->exists());
        $this->assertCount(0, $this->seller->fresh()->notifications);

        $operator = User::factory()->create(['is_platform_operator' => true]);
        $this->actingAs($operator)->get(route('operator.payments.index'))
            ->assertOk()
            ->assertSee($order->reference())
            ->assertSee('Payment received for a cancelled order');
    }

    public function test_a_second_successful_charge_for_an_already_paid_order_goes_to_review(): void
    {
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();
        // a second attempt that was open in another tab when the first one was paid
        $second = Payment::create([
            'order_id' => $this->order->id, 'provider' => 'paystack', 'reference' => 'EMALL-'.$this->order->id.'-secondtab000001',
            'amount_cents' => 16000, 'currency' => 'ZAR', 'status' => PaymentAttemptStatus::Pending,
        ]);

        $this->postWebhook($this->chargeSuccessPayload($second, ['id' => 555000111]))->assertOk();

        $this->assertSame(PaymentAttemptStatus::NeedsReview, $second->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
        $this->assertCount(1, $this->seller->fresh()->notifications);
    }

    public function test_paying_after_a_line_was_cancelled_marks_paid_and_queues_a_refund_for_the_difference(): void
    {
        $other = $this->makeListing($this->seller, ['price' => 40, 'stock' => 5]);
        $order = $this->placeOrder($this->makeBuyer(), [[$other, 1], [$this->makeListing($this->seller, ['price' => 60]), 1]]);
        $attempt = $this->startAttempt($order);
        $this->assertSame(10000, $attempt->amount_cents);
        app(OrderLifecycle::class)->cancelItem($order->items->firstWhere('product_id', $other->id));

        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 99, 'status' => 'pending']], 200)]);
        $this->postWebhook($this->chargeSuccessPayload($attempt))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'amount_cents' => 4000]);
    }

    // ---- refund events ---------------------------------------------------

    public function test_refund_events_update_the_refund_and_the_order(): void
    {
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 3018284, 'status' => 'pending']], 200)]);
        foreach ($this->order->fresh()->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line);
        }
        $refund = Refund::firstOrFail();
        $this->assertSame('refund_submitted', $refund->status->value);

        $this->postWebhook(['event' => 'refund.processed', 'data' => ['id' => 3018284, 'status' => 'processed', 'amount' => 16000, 'currency' => 'ZAR', 'transaction_reference' => $this->payment->reference]])->assertOk();

        $this->assertSame('refunded', $refund->fresh()->status->value);
        $this->assertSame(PaymentStatus::Refunded, $this->order->fresh()->payment_status);
    }

    public function test_a_failed_refund_event_flags_the_refund_for_a_human(): void
    {
        $this->postWebhook($this->chargeSuccessPayload($this->payment))->assertOk();
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 3018284, 'status' => 'pending']], 200)]);
        foreach ($this->order->fresh()->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line);
        }

        $this->postWebhook(['event' => 'refund.failed', 'data' => ['id' => 3018284, 'status' => 'failed', 'transaction_reference' => $this->payment->reference]])->assertOk();

        $this->assertSame('refund_failed', Refund::firstOrFail()->status->value);
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status, 'the buyer is still out of pocket, so the order is not marked refunded');
    }

    public function test_an_unknown_refund_event_is_recorded_as_unmatched(): void
    {
        $this->postWebhook(['event' => 'refund.processed', 'data' => ['id' => 424242, 'status' => 'processed']])->assertOk();

        $this->assertTrue(PaymentEvent::where('outcome', 'unmatched')->where('event', 'refund.processed')->exists());
    }
}
