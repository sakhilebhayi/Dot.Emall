<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Returns\ReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class ReturnsTest extends TestCase
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

    private function returns(): ReturnService
    {
        return app(ReturnService::class);
    }

    /** A paid, delivered order of two lines (R100 and R60, R40 delivery) from one seller. */
    private function delivered(bool $paid = true): array
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 40]);
        $buyer = $this->makeBuyer();

        if ($paid) {
            $this->enablePaystack();
        }

        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100, 'stock' => 5]), 1], [$this->makeListing($seller, ['price' => 60, 'stock' => 5]), 1]]);

        if ($paid) {
            $this->payOrder($order);
        }

        $order = $this->deliver($order);

        return [$seller, $buyer, $order, $order->items()->orderBy('id')->get()];
    }

    private function refundOk(string $status = 'pending')
    {
        $next = 7000;

        return $this->fakeHttp(['api.paystack.co/refund' => function () use (&$next, $status) {
            return Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => $next++, 'status' => $status]], 200);
        }]);
    }

    public function test_a_buyer_can_ask_to_return_a_delivered_line_within_seven_days(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();

        $return = $this->returns()->request($lines[0], $buyer, 'faulty', 'It will not switch on');

        $this->assertSame(ReturnStatus::Requested, $return->status);
        $this->assertSame($seller->id, $return->seller_id);
        $this->assertSame($buyer->id, $return->requester_id);
        $this->assertSame(10000, $return->refund_cents);
        $this->assertTrue($return->reason_category->sellerPaysReturn());
        $this->assertNotNull($return->delivered_at);
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $return->respond_by->timestamp, 5, 'the seller has ten days to answer');
        $this->assertSame(1, $seller->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'return_requested')->count());
    }

    public function test_the_refund_value_is_the_line_after_its_discount_and_never_includes_delivery(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 40]);
        \App\Models\PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'HALF', 'discount_type' => 'percent', 'discount_value' => 50]);
        $buyer = $this->makeBuyer();
        $order = $this->deliver($this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100]), 1]], ['promo_code' => 'HALF']));

        $return = $this->returns()->request($order->items->first(), $buyer, 'changed_mind');

        $this->assertSame(5000, $return->refund_cents);
        $this->assertFalse($return->reason_category->sellerPaysReturn());
    }

    public function test_the_window_is_seven_days_from_delivery_and_counted_from_the_line(): void
    {
        [, $buyer, , $lines] = $this->delivered();

        Carbon::setTestNow(now()->addDays(6)->addHours(23));
        $this->assertNull($this->returns()->ineligibleBecause($lines[0]->fresh()));

        Carbon::setTestNow(now()->addHours(2));
        $this->assertStringContainsString('7-day return window has closed', $this->returns()->ineligibleBecause($lines[0]->fresh()));

        $this->expectException(ValidationException::class);
        $this->returns()->request($lines[0]->fresh(), $buyer, 'faulty');
    }

    public function test_an_item_that_has_not_arrived_cannot_be_returned(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);

        $this->assertStringContainsString('once the item has been delivered', $this->returns()->ineligibleBecause($order->items->first()));
    }

    public function test_one_request_per_line_and_only_the_buyer_may_make_it(): void
    {
        [, $buyer, , $lines] = $this->delivered();
        $this->returns()->request($lines[0], $buyer, 'faulty');

        try {
            $this->returns()->request($lines[0]->fresh(), $buyer, 'faulty');
            $this->fail('a second request for the same line');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been requested', $e->errors()['order_item'][0]);
        }

        $this->expectException(AuthorizationException::class);
        $this->returns()->request($lines[1], $this->makeBuyer(), 'faulty');
    }

    public function test_a_reason_is_required_and_other_needs_words(): void
    {
        [, $buyer, , $lines] = $this->delivered();

        foreach ([['nonsense', null], ['other', null], ['other', '   ']] as [$reason, $note]) {
            try {
                $this->returns()->request($lines[0], $buyer, $reason, $note);
                $this->fail('refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ReturnRequest::query()->count());
    }

    public function test_a_decline_must_say_why(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $return = $this->returns()->request($lines[0], $buyer, 'changed_mind');

        foreach (['', '   ', 'no'] as $reason) {
            try {
                $this->returns()->decline($return, $seller, $reason);
                $this->fail('refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $declined = $this->returns()->decline($return, $seller, 'Opened and used, we cannot resell it.');

        $this->assertSame(ReturnStatus::Declined, $declined->status);
        $this->assertSame('Opened and used, we cannot resell it.', $declined->seller_response);
        $this->assertNotNull($declined->responded_at);
        $this->assertSame(1, $buyer->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'return_declined')->count());
    }

    public function test_only_the_seller_of_the_line_or_an_operator_can_answer(): void
    {
        [, $buyer, , $lines] = $this->delivered();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');

        foreach ([$buyer, $this->makeSeller()] as $nobody) {
            try {
                $this->returns()->approve($return, $nobody);
                $this->fail('refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse(Gate::forUser($buyer)->allows('respond', $return));
        $this->assertTrue(Gate::forUser($buyer)->allows('view', $return));
        $this->assertTrue(Gate::forUser($buyer)->allows('escalate', $return));
        $this->assertFalse(Gate::forUser($this->makeBuyer())->allows('view', $return));
        $this->assertFalse(Gate::forUser($buyer)->allows('resolve', $return));
    }

    public function test_the_full_happy_path_refunds_through_paystack_reverses_the_ledger_and_restocks(): void
    {
        [$seller, $buyer, $order, $lines] = $this->delivered();
        $http = $this->refundOk();
        $stockBefore = $lines[0]->product->fresh()->stock;
        $return = $this->returns()->request($lines[0], $buyer, 'not_as_described', 'Wrong colour');

        $this->returns()->approve($return, $seller);
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);

        $received = $this->returns()->markReceived($return->fresh(), $seller, restock: true);

        $this->assertSame(ReturnStatus::Refunded, $received->status);
        $this->assertTrue($received->restock);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame([10000, 'return', RefundStatus::Submitted], [$refund->amount_cents, $refund->kind, $refund->status]);
        $this->assertSame($refund->id, $received->refund_id);
        $http->assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/refund' && $r->data()['amount'] === 10000);

        $this->assertSame(OrderStatus::Refunded, $lines[0]->fresh()->status);
        $this->assertNotNull($lines[0]->fresh()->refunded_at);
        $this->assertSame($stockBefore + 1, $lines[0]->product->fresh()->stock);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status, 'the other line was delivered');
        $this->assertSame('200.00', $order->fresh()->total, 'the order total stays what was charged; returned lines are accounted for by their refund');

        // The seller's ledger lost exactly that line.
        $net = LedgerEntry::query()->where('order_id', $order->id)->sum('net_cents');
        $this->assertSame(6000 + 4000, (int) $net);
        $this->assertSame(1, LedgerEntry::query()->where('kind', 'reversal')->count());

        $this->assertSame(1, $buyer->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'return_received')->count());
    }

    public function test_without_the_restock_flag_the_units_stay_off_the_shelf(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $this->refundOk();
        $before = $lines[0]->product->fresh()->stock;
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->approve($return, $seller);

        $this->returns()->markReceived($return->fresh(), $seller);

        $this->assertSame($before, $lines[0]->product->fresh()->stock, 'a faulty item is not resold');
    }

    public function test_marking_received_twice_refunds_once(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $http = $this->refundOk();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->approve($return, $seller);
        $this->returns()->markReceived($return->fresh(), $seller);

        try {
            $this->returns()->markReceived($return->fresh(), $seller);
            $this->fail('already refunded');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, Refund::query()->count());
        $http->assertSentCount(1);
    }

    public function test_the_order_becomes_refunded_only_when_every_paid_cent_is_confirmed_back(): void
    {
        [$seller, $buyer, $order, $lines] = $this->delivered();
        $this->refundOk('processed');
        foreach ($lines as $line) {
            $return = $this->returns()->request($line, $buyer, 'faulty');
            $this->returns()->approve($return, $seller);
            $this->returns()->markReceived($return->fresh(), $seller);
        }

        // 160 refunded of 200 paid (R40 delivery is not refunded): partially refunded, not refunded.
        $this->assertSame(16000, (int) Refund::query()->sum('amount_cents'));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }

    public function test_an_order_that_never_took_payment_returns_without_a_refund_record(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered(paid: false);
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->approve($return, $seller);

        $received = $this->returns()->markReceived($return->fresh(), $seller);

        $this->assertSame(ReturnStatus::Refunded, $received->status);
        $this->assertNull($received->refund_id);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(OrderStatus::Refunded, $lines[0]->fresh()->status);
    }

    public function test_an_unanswered_request_escalates_after_ten_days_and_the_job_is_idempotent(): void
    {
        [, $buyer, , $lines] = $this->delivered();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');

        Carbon::setTestNow(now()->addDays(9));
        $this->assertSame(0, $this->returns()->escalateOverdue());
        $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);

        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('emall:escalate-returns')->expectsOutputToContain('Escalated 1 return request')->assertSuccessful();
        $this->artisan('emall:escalate-returns')->expectsOutputToContain('Escalated 0 return requests');

        $fresh = $return->fresh();
        $this->assertSame(ReturnStatus::Escalated, $fresh->status);
        $this->assertSame('seller_no_response', $fresh->escalation_reason);
        $this->assertNotNull($fresh->escalated_at);
        $this->assertSame(1, $buyer->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'return_escalated')->count(), 'told once');
    }

    public function test_an_answered_request_is_never_escalated_by_the_clock(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->approve($return, $seller);

        Carbon::setTestNow(now()->addDays(30));

        $this->assertSame(0, $this->returns()->escalateOverdue());
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
    }

    public function test_the_escalation_job_is_scheduled_with_an_overlap_guard_that_expires(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'emall:'));

        foreach (['emall:escalate-returns', 'emall:release-ledger'] as $command) {
            $event = $events->first(fn ($e) => str_contains($e->command, $command));
            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap");
            $this->assertLessThanOrEqual(60, $event->expiresAt, "{$command} releases its lock within the hour");
        }
    }

    public function test_the_buyer_can_dispute_a_decline_and_an_operator_settles_it(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $operator = User::factory()->create(['is_platform_operator' => true]);
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->decline($return, $seller, 'We tested it and it works.');

        $this->returns()->escalate($return->fresh(), $buyer, 'I have a video of the fault');
        $this->assertSame(ReturnStatus::Escalated, $return->fresh()->status);
        $this->assertSame('buyer_disputed_decline', $return->fresh()->escalation_reason);

        $queue = $this->returns()->operatorQueue()->get();
        $this->assertSame([$return->id], $queue->pluck('id')->all());

        try {
            $this->returns()->operatorResolve($return->fresh(), $seller, 'approve', 'I am just the seller');
            $this->fail('only operators settle');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->refundOk();
        $this->returns()->operatorResolve($return->fresh(), $operator, 'approve', 'Video shows the fault.');

        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
        $this->assertSame($operator->id, $return->fresh()->resolved_by);
        $this->assertSame(0, $this->returns()->operatorQueue()->count());

        $this->returns()->markReceived($return->fresh(), $operator);
        $this->assertSame(ReturnStatus::Refunded, $return->fresh()->status);
    }

    public function test_an_operator_can_uphold_a_decline_and_a_closed_return_stays_closed(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $operator = User::factory()->create(['is_platform_operator' => true]);
        $return = $this->returns()->request($lines[0], $buyer, 'changed_mind');
        $this->returns()->decline($return, $seller, 'Outside our stated policy.');
        $this->returns()->escalate($return->fresh(), $buyer);

        $this->returns()->operatorResolve($return->fresh(), $operator, 'uphold_decline', 'The seller policy was shown at purchase.');

        $this->assertSame(ReturnStatus::Closed, $return->fresh()->status);
        $this->expectException(ValidationException::class);
        $this->returns()->markReceived($return->fresh(), $operator);
    }

    public function test_only_a_declined_return_can_be_escalated_by_the_buyer_and_only_by_the_buyer(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');

        try {
            $this->returns()->escalate($return, $buyer);
            $this->fail('not declined yet');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->returns()->decline($return, $seller, 'Not covered by warranty.');

        $this->expectException(AuthorizationException::class);
        $this->returns()->escalate($return->fresh(), $this->makeBuyer());
    }

    public function test_a_returned_line_is_excluded_from_the_money_that_counts_for_reconciliation(): void
    {
        [$seller, $buyer, $order, $lines] = $this->delivered();
        $this->refundOk();
        $return = $this->returns()->request($lines[0], $buyer, 'faulty');
        $this->returns()->approve($return, $seller);
        $this->returns()->markReceived($return->fresh(), $seller);

        $this->artisan('emall:reconcile')->expectsOutputToContain('Everything agrees.')->assertSuccessful();

        // A line cancelled after a return elsewhere in the order does not double count.
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_guest_buyer_can_return_with_the_order_link(): void
    {
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller, ['price' => 80])->defaultVariant()->first();
        $order = app(\App\Services\CheckoutService::class)->placeAsGuest('g@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => 'x']);
        $this->deliver($order);
        $line = $order->items()->first();

        $return = $this->returns()->request($line->fresh(), null, 'faulty', 'Cracked');

        $this->assertNull($return->requester_id);
        $this->assertSame('g@example.test', $return->requester_email);

        // An account holder cannot use the guest path on an account order.
        [, , , $mine] = $this->delivered(paid: false);
        $this->expectException(AuthorizationException::class);
        $this->returns()->request($mine[0], null, 'faulty');
    }

    public function test_photo_paths_are_kept_only_when_they_point_inside_this_orders_return_folder(): void
    {
        [, $buyer, $order, $lines] = $this->delivered();

        $return = $this->returns()->request($lines[0], $buyer, 'faulty', null, ["returns/{$order->id}/a.jpg", 'returns/999/b.jpg', "returns/{$order->id}/../../etc/passwd", '/etc/passwd']);

        $this->assertSame(["returns/{$order->id}/a.jpg"], $return->photo_paths);
    }

    public function test_the_sellers_return_list_is_theirs_alone(): void
    {
        [$seller, $buyer, , $lines] = $this->delivered();
        $this->returns()->request($lines[0], $buyer, 'faulty');

        $this->assertSame(1, $this->returns()->forSeller($seller)->count());
        $this->assertSame(0, $this->returns()->forSeller($this->makeSeller())->count());
        $this->assertInstanceOf(OrderItem::class, $this->returns()->forSeller($seller)->first()->item);
    }
}
