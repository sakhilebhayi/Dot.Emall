<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\OrderStatusHistory;
use App\Services\CheckoutService;
use App\Services\OrderAccess;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class OrderStateMachineTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function life(): OrderLifecycle
    {
        return app(OrderLifecycle::class);
    }

    private function simple(array $listing = [], array $delivery = []): array
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, $delivery + ['price' => 50]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, $listing + ['price' => 100]), 1]]);

        return [$seller, $buyer, $order, $order->items->first()];
    }

    public function test_the_whole_delivery_path_is_legal_and_each_step_is_stamped_and_logged(): void
    {
        [$seller, , $order, $line] = $this->simple();

        $this->life()->advanceItem($line, OrderStatus::Processing, $seller, 'Picking now');
        $this->life()->advanceItem($line->fresh(), OrderStatus::Shipped, $seller);
        $this->life()->advanceItem($line->fresh(), OrderStatus::Delivered, $seller);

        $line = $line->fresh();
        $this->assertSame(OrderStatus::Delivered, $line->status);
        $this->assertNotNull($line->processing_at);
        $this->assertNotNull($line->shipped_at);
        $this->assertNotNull($line->delivered_at);
        $this->assertNull($line->cancelled_at);

        $history = OrderStatusHistory::query()->where('order_item_id', $line->id)->orderBy('id')->get();
        $this->assertSame([['pending', 'processing'], ['processing', 'shipped'], ['shipped', 'delivered']], $history->map(fn ($h) => [$h->from_status, $h->to_status])->all());
        $this->assertSame([$seller->id, $seller->id, $seller->id], $history->pluck('actor_id')->all());
        $this->assertSame('seller', $history[0]->actor_role);
        $this->assertSame('Picking now', $history[0]->note);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertSame(OrderStatus::Delivered, OrderStatus::from($order->groups()->first()->status));
        $this->assertNotNull($order->groups()->first()->delivered_at);
    }

    public function test_illegal_moves_are_refused_and_change_nothing(): void
    {
        [$seller, , , $line] = $this->simple();

        // No jump from pending to delivered.
        foreach ([OrderStatus::Delivered, OrderStatus::Collected, OrderStatus::Refunded, OrderStatus::Pending] as $to) {
            try {
                $this->life()->advanceItem($line, $to, $seller);
                $this->fail("pending -> {$to->value} must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('That status change is not allowed.', $e->getMessage());
            }
        }

        $this->life()->advanceItem($line, OrderStatus::Shipped, $seller);

        foreach ([OrderStatus::Processing, OrderStatus::Pending, OrderStatus::ReadyForCollection, OrderStatus::Collected] as $to) {
            $this->expectsRefusal(fn () => $this->life()->advanceItem($line->fresh(), $to, $seller), "shipped -> {$to->value}");
        }

        $this->life()->advanceItem($line->fresh(), OrderStatus::Delivered, $seller);

        foreach ([OrderStatus::Shipped, OrderStatus::Processing, OrderStatus::Cancelled] as $to) {
            $this->expectsRefusal(fn () => $this->life()->advanceItem($line->fresh(), $to, $seller), "delivered -> {$to->value}");
        }

        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);
        $this->assertSame(2, OrderStatusHistory::query()->where('order_item_id', $line->id)->count(), 'only the two real moves are logged; refused moves leave no history');
    }

    public function test_a_seller_can_never_mark_a_line_refunded_only_the_system_does(): void
    {
        [$seller, , , $line] = $this->simple();
        $this->life()->advanceItem($line, OrderStatus::Shipped, $seller);
        $this->life()->advanceItem($line->fresh(), OrderStatus::Delivered, $seller);

        $this->expectsRefusal(fn () => $this->life()->advanceItem($line->fresh(), OrderStatus::Refunded, $seller), 'seller refunding');
        $this->actingAs($seller)->patch(route('seller.order-items.update-status', $line), ['status' => 'refunded'])->assertSessionHasErrors('status');

        $this->life()->markRefunded($line->fresh(), null, 'test');
        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);
        $this->assertSame('system', OrderStatusHistory::query()->where('order_item_id', $line->id)->latest('id')->first()->actor_role);
    }

    public function test_collection_parcels_go_ready_then_collected_and_cannot_be_shipped(): void
    {
        [$seller, $buyer, $order, $line] = $this->simple([], ['type' => 'collection', 'price' => 0, 'collection_address' => '1 Depot Road']);

        $this->expectsRefusal(fn () => $this->life()->advanceItem($line, OrderStatus::Shipped, $seller), 'collection parcel shipped');
        $this->expectsRefusal(fn () => $this->life()->advanceItem($line, OrderStatus::Collected, $seller), 'pending -> collected');

        $this->life()->advanceItem($line->fresh(), OrderStatus::ReadyForCollection, $seller);
        $this->assertNotNull($line->fresh()->ready_at);
        $this->assertSame(OrderStatus::ReadyForCollection, $order->fresh()->status);

        $this->life()->advanceItem($line->fresh(), OrderStatus::Collected, $seller);

        $this->assertSame(OrderStatus::Collected, $line->fresh()->status);
        $this->assertNotNull($line->fresh()->collected_at);
        $this->assertSame(OrderStatus::Collected, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_a_delivery_parcel_cannot_be_marked_ready_for_collection(): void
    {
        [$seller, , , $line] = $this->simple();

        $this->expectsRefusal(fn () => $this->life()->advanceItem($line, OrderStatus::ReadyForCollection, $seller), 'delivery parcel ready for collection');
    }

    public function test_the_buyer_confirms_receipt_but_cannot_do_anything_else(): void
    {
        [$seller, $buyer, $order, $line] = $this->simple();

        $this->expectsRefusal(fn () => $this->life()->confirmReceipt($line, $buyer), 'confirming a pending line');
        $this->expectsRefusal(fn () => $this->life()->advanceItem($line, OrderStatus::Shipped, $buyer, null, 'buyer'), 'buyer shipping');

        $this->life()->advanceItem($line->fresh(), OrderStatus::Shipped, $seller);
        $this->life()->confirmReceipt($line->fresh(), $buyer);

        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);
        $last = OrderStatusHistory::query()->where('order_item_id', $line->id)->latest('id')->first();
        $this->assertSame(['buyer', $buyer->id, 'Confirmed by the buyer'], [$last->actor_role, $last->actor_id, $last->note]);
    }

    public function test_someone_else_cannot_confirm_receipt(): void
    {
        [$seller, , , $line] = $this->simple();
        $this->life()->advanceItem($line, OrderStatus::Shipped, $seller);

        $this->expectException(InvalidArgumentException::class);
        $this->life()->confirmReceipt($line->fresh(), $this->makeBuyer());
    }

    public function test_a_guest_holding_the_link_can_confirm_receipt_as_a_guest(): void
    {
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller)->defaultVariant()->first();
        $order = app(CheckoutService::class)->placeAsGuest('g@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => 'x']);
        $line = $order->items()->first();
        $this->life()->advanceItem($line, OrderStatus::Shipped, $seller);

        $this->life()->confirmReceipt($line->fresh(), null);

        $this->assertSame('guest', OrderStatusHistory::query()->where('order_item_id', $line->id)->latest('id')->value('actor_role'));
    }

    public function test_the_order_and_parcel_status_follow_their_lines(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $other = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1], [$this->makeListing($seller), 1], [$this->makeListing($other), 1]]);
        [$a, $b, $c] = $order->items->all();

        $this->life()->advanceItem($a, OrderStatus::Shipped, $seller);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        $this->life()->advanceItem($b, OrderStatus::Shipped, $seller);
        $this->life()->advanceItem($c, OrderStatus::Shipped, $other);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);

        $this->life()->advanceItem($a->fresh(), OrderStatus::Delivered, $seller);
        $group = $order->groups()->where('seller_id', $seller->id)->first();
        $this->assertSame('shipped', $group->fresh()->status, 'one line of two delivered: the parcel is not');
        $this->assertNull($group->fresh()->delivered_at);

        $this->life()->advanceItem($b->fresh(), OrderStatus::Delivered, $seller);
        $this->assertNotNull($group->fresh()->delivered_at);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status, 'the other seller has not delivered');
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_cancelling_is_logged_with_who_and_a_note_and_still_restocks_once(): void
    {
        [, $buyer, $order, $line] = $this->simple(['stock' => 10]);
        $product = $line->product;

        $this->life()->cancelItem($line, $buyer, 'Ordered by mistake');
        $this->life()->cancelItem($line->fresh(), $buyer);

        $this->assertSame(10, $product->fresh()->stock);
        $rows = OrderStatusHistory::query()->where('order_item_id', $line->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['pending', 'cancelled', 'buyer', 'Ordered by mistake'], [$rows[0]->from_status, $rows[0]->to_status, $rows[0]->actor_role, $rows[0]->note]);
        $this->assertNotNull($line->fresh()->cancelled_at);
    }

    public function test_the_history_is_visible_to_the_buyer_and_only_the_sellers_own_lines_to_a_seller(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($a), 1], [$this->makeListing($b), 1]]);
        $this->life()->advanceItem($order->items[0], OrderStatus::Processing, $a);
        $this->life()->advanceItem($order->items[1], OrderStatus::Processing, $b);

        $access = app(OrderAccess::class);

        $this->assertCount(2, $access->present($order->fresh(), $buyer)['history']);
        $this->assertCount(1, $access->present($order->fresh(), $a)['history']);
        $this->assertCount(1, $access->present($order->fresh(), $a)['groups'], 'a seller sees only their own parcel');
        $this->assertCount(2, $access->present($order->fresh(), $buyer)['groups']);
    }

    public function test_delivery_starts_the_ledger_release_clock_only_when_the_whole_parcel_is_delivered(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller, ['price' => 100]), 1], [$this->makeListing($seller, ['price' => 50]), 1]]);
        $this->payOrder($order);
        [$a, $b] = $order->items->all();
        $entry = fn () => LedgerEntry::query()->where('order_id', $order->id)->where('kind', 'sale')->first();

        $this->life()->advanceItem($a, OrderStatus::Shipped, $seller);
        $this->life()->advanceItem($b, OrderStatus::Shipped, $seller);
        $this->life()->advanceItem($a->fresh(), OrderStatus::Delivered, $seller);
        $this->assertNull($entry()->release_at);

        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->life()->advanceItem($b->fresh(), OrderStatus::Delivered, $seller);

        $this->assertSame('2026-11-16 12:00:00', $entry()->release_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    private function expectsRefusal(callable $do, string $what): void
    {
        try {
            $do();
            $this->fail("{$what} must be refused");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_seller_controller_reports_a_refused_move_instead_of_failing(): void
    {
        [$seller, , , $line] = $this->simple();

        $this->actingAs($seller)->patch(route('seller.order-items.update-status', $line), ['status' => 'delivered'])->assertSessionHasErrors('status');
        $this->actingAs($seller)->patch(route('seller.order-items.update-status', $line), ['status' => 'processing'])->assertSessionHasNoErrors();
        $this->assertSame('Packing', $line->fresh()->status->label());
    }
}
