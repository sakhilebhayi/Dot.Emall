<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\StoreCommissionOverride;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\Reconciler;
use App\Services\Ledger\SellerFees;
use App\Services\OrderLifecycle;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class LedgerAndReconcileTest extends TestCase
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

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function refundsAccepted(): void
    {
        $next = 9100;
        $this->fakeHttp(['api.paystack.co/refund' => function () use (&$next) {
            return Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => $next++, 'status' => 'pending']], 200);
        }]);
    }

    /** @return array{0: User, 1: User, 2: Order} a paid order of R100 + R60 items and R40 delivery from one seller */
    private function paid(array $storeAttributes = []): array
    {
        $this->enablePaystack();
        $seller = $this->makeSeller($storeAttributes);
        $this->withDelivery($seller, ['price' => 40]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100]), 1], [$this->makeListing($seller, ['price' => 60]), 1]]);
        $this->payOrder($order);

        return [$seller, $buyer, $order->fresh()];
    }

    public function test_paying_an_order_writes_one_held_entry_per_seller_group_with_the_fee_taken(): void
    {
        config(['emall.commission.percent' => 10]);
        $this->enablePaystack();
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $this->withDelivery($a, ['price' => 40]);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($a, ['price' => 100]), 1], [$this->makeListing($a, ['price' => 60]), 1], [$this->makeListing($b, ['price' => 50]), 2]]);
        $this->payOrder($order);

        $entries = LedgerEntry::query()->where('order_id', $order->id)->orderBy('seller_id')->get();
        $this->assertCount(2, $entries);

        $mine = $entries->firstWhere('seller_id', $a->id);
        $this->assertSame(['sale', 'held', 'ZAR'], [$mine->kind, $mine->status, $mine->currency]);
        $this->assertSame(20000, $mine->gross_cents, 'items 160 + delivery 40');
        $this->assertSame(1600, $mine->commission_cents, '10% of the items, not of delivery');
        $this->assertSame(18400, $mine->net_cents);
        $this->assertEquals(10, $mine->commission_percent);
        $this->assertNull($mine->release_at);

        $other = $entries->firstWhere('seller_id', $b->id);
        $this->assertSame([10000, 1000, 9000], [$other->gross_cents, $other->commission_cents, $other->net_cents]);
    }

    public function test_the_default_commission_is_zero(): void
    {
        [, , $order] = $this->paid();

        $entry = LedgerEntry::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame([20000, 0, 20000], [$entry->gross_cents, $entry->commission_cents, $entry->net_cents]);
    }

    public function test_a_store_override_beats_the_default_and_a_later_change_never_rewrites_a_sale(): void
    {
        config(['emall.commission.percent' => 5]);
        [$seller, , $order] = $this->paid();
        $this->assertSame(800, LedgerEntry::query()->where('order_id', $order->id)->value('commission_cents'));

        config(['emall.commission.percent' => 20]);
        StoreCommissionOverride::create(['store_id' => $seller->store->id, 'percent' => 2.5, 'note' => 'founding seller']);

        $this->assertEquals(2.5, $this->ledger()->commissionPercent($seller->store->id));
        $this->assertSame(800, LedgerEntry::query()->where('order_id', $order->id)->value('commission_cents'), 'the sale keeps the percentage it was made under');
        $this->assertSame(20.0, $this->ledger()->commissionPercent(null));
    }

    public function test_a_sale_is_never_recorded_for_money_that_has_not_arrived(): void
    {
        $this->enablePaystack();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]]);

        $this->assertSame(0, $this->ledger()->recordSale($order));
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_recording_is_idempotent(): void
    {
        [, , $order] = $this->paid();

        $this->assertSame(0, $this->ledger()->recordSale($order));
        $this->assertSame(0, $this->ledger()->recordSale($order));
        $this->assertSame(1, LedgerEntry::query()->where('order_id', $order->id)->count());
    }

    public function test_unpaid_orders_and_free_orders_earn_nothing(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $unpaid = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'ALLFREE', 'discount_type' => 'percent', 'discount_value' => 100]);
        $free = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]], ['promo_code' => 'ALLFREE']);
        $this->payOrder($free); // settles at zero

        $this->assertSame(0, LedgerEntry::query()->where('order_id', $unpaid->id)->count());
        $this->assertSame(0, LedgerEntry::query()->where('order_id', $free->id)->count());
    }

    public function test_cancelling_a_line_of_a_paid_order_reverses_exactly_that_lines_share(): void
    {
        [$seller, $buyer, $order] = $this->paid();
        $this->refundsAccepted();

        app(OrderLifecycle::class)->cancelItem($order->items->firstWhere('total', '60.00'), $buyer);

        $entries = LedgerEntry::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['sale', 'reversal'], $entries->pluck('kind')->all());
        $this->assertSame([-6000, 0, -6000], [$entries[1]->gross_cents, $entries[1]->commission_cents, $entries[1]->net_cents]);
        $this->assertSame($entries[0]->id, $entries[1]->reverses_entry_id);
        $this->assertSame(14000, (int) $entries->sum('net_cents'), '100 + 40 delivery remain');
        $this->assertSame('held', $entries[0]->status);
    }

    public function test_cancelling_the_whole_group_reverses_the_delivery_fee_too_and_marks_the_sale_reversed(): void
    {
        [$seller, $buyer, $order] = $this->paid();
        $this->refundsAccepted();

        foreach ($order->items as $line) {
            app(OrderLifecycle::class)->cancelItem($line, $buyer);
        }

        $this->assertSame(0, (int) LedgerEntry::query()->where('order_id', $order->id)->sum('gross_cents'));
        $this->assertSame('reversed', LedgerEntry::query()->where('kind', 'sale')->value('status'));
        $this->artisan('emall:reconcile')->assertSuccessful();
    }

    public function test_entries_release_seven_days_after_delivery_and_not_before(): void
    {
        [$seller, , $order] = $this->paid();
        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->deliver($order);

        Carbon::setTestNow('2026-11-16 11:59:00');
        $this->assertSame(0, $this->ledger()->releaseDue()['released']);

        Carbon::setTestNow('2026-11-16 12:01:00');
        $result = $this->ledger()->releaseDue();

        $this->assertSame(1, $result['released']);
        $this->assertSame(20000, $result['net_cents']);
        $entry = LedgerEntry::query()->where('order_id', $order->id)->first();
        $this->assertSame('released', $entry->status);
        $this->assertNotNull($entry->released_at);
        $this->assertSame(1, $seller->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'ledger_released')->count());
    }

    public function test_the_release_job_is_idempotent_and_never_calls_paystack(): void
    {
        [, , $order] = $this->paid();
        $this->deliver($order);
        Carbon::setTestNow(now()->addDays(8));
        Http::fake()->preventStrayRequests();

        $this->artisan('emall:release-ledger')->expectsOutputToContain('Released 1 sale(s)')->assertSuccessful();
        $this->artisan('emall:release-ledger')->expectsOutputToContain('Released 0 sale(s)')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, LedgerEntry::query()->where('status', 'released')->count());
    }

    public function test_an_open_return_holds_the_release_back(): void
    {
        [$seller, $buyer, $order] = $this->paid();
        $order = $this->deliver($order);
        app(ReturnService::class)->request($order->items->first(), $buyer, 'faulty');

        Carbon::setTestNow(now()->addDays(8));
        $result = $this->ledger()->releaseDue();

        $this->assertSame([0, 1], [$result['released'], $result['skipped_open_returns']]);
        $this->assertSame('held', LedgerEntry::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_a_refund_after_release_is_a_negative_entry_against_the_released_balance(): void
    {
        [$seller, $buyer, $order] = $this->paid();
        $order = $this->deliver($order);
        Carbon::setTestNow(now()->addDays(8));
        $this->ledger()->releaseDue();
        $this->refundsAccepted();

        $returns = app(ReturnService::class);
        Carbon::setTestNow(now()->subDays(2)); // still inside the 7 days from delivery for the request
        $return = $returns->request($order->items->firstWhere('total', '60.00'), $buyer, 'faulty');
        Carbon::setTestNow(now()->addDays(2));
        $returns->approve($return, $seller);
        $returns->markReceived($return->fresh(), $seller);

        $balance = $this->ledger()->balance($seller)['ZAR'];
        $this->assertSame(['held' => 0, 'released' => 14000], ['held' => $balance['held'], 'released' => $balance['released']]);
    }

    public function test_balance_adds_held_and_released_and_nets_reversals(): void
    {
        [$seller, $buyer, $order] = $this->paid();
        $this->assertSame(['held' => 20000, 'released' => 0, 'commission' => 0], $this->ledger()->balance($seller)['ZAR']);
        $this->assertSame([], $this->ledger()->balance($buyer));
    }

    public function test_the_fee_disclosure_says_what_the_ledger_does(): void
    {
        $zero = app(SellerFees::class)->disclosure();
        $this->assertStringContainsString('takes no commission', $zero);
        $this->assertStringContainsString('released 7 days after delivery', $zero);
        $this->assertStringContainsString('does not pay out automatically', $zero);

        config(['emall.commission.percent' => 7.5]);
        $seller = $this->makeSeller();
        $this->assertStringContainsString('7.5% of the item price', app(SellerFees::class)->disclosure($seller->store));

        StoreCommissionOverride::create(['store_id' => $seller->store->id, 'percent' => 4]);
        $this->assertStringContainsString('4% of the item price', app(SellerFees::class)->disclosure($seller->fresh()->store));
        $this->assertStringContainsString('not charged on delivery fees', app(SellerFees::class)->disclosure($seller->fresh()->store));
    }

    // ---- reconciliation ----------------------------------------------------------------------------------------------

    public function test_reconcile_agrees_on_a_healthy_set_of_books(): void
    {
        [, $buyer, $order] = $this->paid();
        $this->deliver($order);

        $this->artisan('emall:reconcile')->expectsOutputToContain('Checked 1 paid orders.')->expectsOutputToContain('Everything agrees.')->assertSuccessful();
    }

    public function test_reconcile_fails_loudly_when_money_and_orders_disagree(): void
    {
        [, , $order] = $this->paid();
        Log::spy();

        Payment::query()->where('order_id', $order->id)->update(['amount_cents' => 19000]);

        $this->artisan('emall:reconcile')->expectsOutputToContain('mismatch')->assertFailed();
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_reconcile_catches_a_ledger_that_drifted_from_the_order(): void
    {
        [, , $order] = $this->paid();

        LedgerEntry::query()->where('order_id', $order->id)->update(['gross_cents' => 1]);

        $result = app(Reconciler::class)->run();

        $this->assertContains('ledger_vs_order', array_column($result['mismatches'], 'check'));
        $this->artisan('emall:reconcile')->assertFailed();
    }

    public function test_reconcile_catches_delivery_that_does_not_add_up_and_orphan_payments(): void
    {
        [, , $order] = $this->paid();

        DB::table('order_groups')->where('order_id', $order->id)->update(['delivery_cents' => 1]);
        Payment::query()->create(['order_id' => null, 'provider' => 'paystack', 'reference' => 'EMALL-X-1', 'amount_cents' => 500, 'currency' => 'ZAR', 'status' => 'success']);
        Refund::query()->create(['payment_id' => Payment::query()->where('reference', 'EMALL-X-1')->value('id'), 'order_id' => $order->id, 'amount_cents' => 100, 'currency' => 'ZAR', 'status' => 'refund_pending', 'kind' => 'cancellation']);

        $checks = array_column(app(Reconciler::class)->run()['mismatches'], 'check');

        $this->assertContains('group_delivery_vs_order', $checks);
        $this->assertContains('payment_without_paid_order', $checks);
        $this->assertContains('paid_less_refunds_vs_total', $checks);
    }

    public function test_reconcile_can_be_limited_to_recent_orders(): void
    {
        [, , $order] = $this->paid();
        Order::query()->whereKey($order->id)->update(['paid_at' => now()->subDays(40)]);
        Payment::query()->where('order_id', $order->id)->update(['amount_cents' => 1]);

        $this->artisan('emall:reconcile', ['--days' => 7])->assertSuccessful();
        $this->artisan('emall:reconcile')->assertFailed();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
