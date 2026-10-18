<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackRefundTest extends TestCase
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
        $a = $this->makeListing($this->seller, ['price' => 40, 'stock' => 9]);
        $b = $this->makeListing($this->seller, ['price' => 60, 'stock' => 9]);
        $this->order = $this->enablePaystackAndPlace($this->buyer, [[$a, 1], [$b, 1]]);
        $this->payment = $this->payOrder($this->order);
    }

    private function refundOk(string $status = 'pending')
    {
        // each refund Paystack creates has its own id
        $next = 3018284;

        return $this->fakeHttp(['api.paystack.co/refund' => function () use (&$next, $status) {
            return Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => $next++, 'status' => $status]], 200);
        }]);
    }

    public function test_a_buyer_cancelling_a_line_of_a_paid_order_requests_a_partial_refund(): void
    {
        $http = $this->refundOk();
        $line = $this->order->items->firstWhere('total', '40.00');

        $this->actingAs($this->buyer)->post(route('order-items.cancel', $line))->assertRedirect();

        $refund = Refund::firstOrFail();
        $this->assertSame(4000, $refund->amount_cents);
        $this->assertSame('ZAR', $refund->currency);
        $this->assertSame($this->payment->id, $refund->payment_id);
        $this->assertSame(RefundStatus::Submitted, $refund->status);
        $this->assertSame('3018284', $refund->provider_refund_id);
        $http->assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/refund'
            && $r->data()['transaction'] === $this->payment->reference
            && $r->data()['amount'] === 4000
            && $r->data()['currency'] === 'ZAR');
        $http->assertSentCount(1);
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status, 'not "refunded" until Paystack says processed');
    }

    public function test_cancelling_the_rest_refunds_the_remainder_and_replays_refund_nothing_more(): void
    {
        $http = $this->refundOk();
        foreach ($this->order->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line);
        }
        foreach ($this->order->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line->fresh()); // replays
        }

        $this->assertSame([4000, 6000], Refund::orderBy('id')->pluck('amount_cents')->all());
        $this->assertSame(10000, Refund::sum('amount_cents'), 'never more than was paid');
        $http->assertSentCount(2);
    }

    public function test_the_seller_cancelling_before_shipping_also_refunds_the_buyer(): void
    {
        $this->refundOk();

        $this->actingAs($this->seller)->post(route('order-items.cancel', $this->order->items->first()))->assertRedirect();

        $this->assertSame(1, Refund::count());
    }

    public function test_a_paystack_outage_still_cancels_but_leaves_a_refund_required_record(): void
    {
        $this->fakeHttp(['api.paystack.co/refund' => fn () => throw new ConnectionException('down')]);
        $line = $this->order->items->first();

        $this->actingAs($this->buyer)->post(route('order-items.cancel', $line))->assertRedirect();

        $this->assertTrue($line->fresh()->isCancelled());
        $refund = Refund::firstOrFail();
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->provider_refund_id);
        $this->assertNotNull($refund->last_error);

        $operator = User::factory()->create(['is_platform_operator' => true]);
        $this->actingAs($operator)->get(route('operator.payments.index'))
            ->assertSee('Refund required')
            ->assertSee('Paystack dashboard');
    }

    public function test_nothing_claims_a_refund_happened_before_paystack_confirms(): void
    {
        $this->refundOk();
        $this->actingAs($this->buyer)->post(route('order-items.cancel', $this->order->items->first()));

        $page = $this->actingAs($this->buyer)->get(route('orders.show', $this->order));

        $page->assertSee('Refund requested')
            ->assertDontSee('Refunded');
    }

    public function test_cancelling_an_unpaid_order_or_a_legacy_order_creates_no_refund_and_makes_no_call(): void
    {
        $http = $this->fakeHttp();
        $unpaid = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller, ['stock' => 5]), 1]]);
        config(['paystack.secret_key' => '']);
        $legacy = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller, ['stock' => 5]), 1]]);
        config(['paystack.secret_key' => $this->testKey]);

        foreach ([$unpaid, $legacy] as $order) {
            app(OrderLifecycle::class)->cancelItem($order->items->first());
        }

        $this->assertDatabaseCount('refunds', 0);
        $http->assertNothingSent();
    }

    public function test_a_shipped_line_cannot_be_cancelled_so_no_refund_is_created(): void
    {
        $this->refundOk();
        $line = $this->order->items->first();
        app(OrderLifecycle::class)->advanceItem($line, OrderStatus::Shipped);

        $this->actingAs($this->buyer)->post(route('order-items.cancel', $line))->assertSessionHasErrors('status');

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_fulfilment_is_unaffected_for_a_paid_order(): void
    {
        $line = $this->order->items->first();

        app(OrderLifecycle::class)->advanceItem($line, OrderStatus::Processing);

        $this->assertSame(OrderStatus::Processing, $line->fresh()->status);
    }

    public function test_operators_can_retry_a_failed_submission_and_mark_one_done_by_hand(): void
    {
        $this->fakeHttp(['api.paystack.co/refund' => fn () => throw new ConnectionException('down')]);
        app(OrderLifecycle::class)->cancelItem($this->order->items->first());
        $refund = Refund::firstOrFail();
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $this->refundOk();
        $this->actingAs($operator)->post(route('operator.refunds.retry', $refund))->assertRedirect(route('operator.payments.index'));
        $this->assertSame(RefundStatus::Submitted, $refund->fresh()->status);

        // a second refund, done in the Paystack dashboard instead
        $this->fakeHttp(['api.paystack.co/refund' => fn () => throw new ConnectionException('down')]);
        app(OrderLifecycle::class)->cancelItem($this->order->items->last());
        $second = Refund::latest('id')->firstOrFail();
        $this->actingAs($operator)->post(route('operator.refunds.mark-done', $second), ['note' => 'Refunded in the Paystack dashboard, ref 123'])
            ->assertRedirect(route('operator.payments.index'));
        $this->assertSame(RefundStatus::Refunded, $second->fresh()->status);
        $this->assertSame($operator->id, $second->fresh()->resolved_by);
    }

    public function test_refund_operator_actions_are_operator_only(): void
    {
        $this->fakeHttp(['api.paystack.co/refund' => fn () => throw new ConnectionException('down')]);
        app(OrderLifecycle::class)->cancelItem($this->order->items->first());
        $refund = Refund::firstOrFail();

        $this->actingAs($this->buyer)->post(route('operator.refunds.retry', $refund))->assertForbidden();
        $this->actingAs($this->seller)->post(route('operator.refunds.mark-done', $refund), ['note' => 'x'])->assertForbidden();
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
    }
}
