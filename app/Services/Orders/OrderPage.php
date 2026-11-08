<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Services\Checkout\StockHold;
use App\Services\Returns\ReturnService;
use App\Services\Shipping\Carriers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The BUYER's view of one order, assembled from real rows only (a guest reaches it through their link, a signed-in buyer through
 * their account): the waybill built from the lines' own timestamps, each seller's parcel with what the seller entered about it,
 * the money, the payment as it really stands, refunds as recorded, and what the buyer may still do. Nothing is invented: a step
 * with no date says so, and a tracking detail is labelled as coming from the seller.
 */
class OrderPage
{
    public function __construct(
        private readonly Carriers $carriers,
        private readonly ReturnService $returns,
        private readonly StockHold $hold,
    ) {}

    /**
     * @param  array<string, mixed>  $access  OrderAccess::present() or presentGuest()
     * @return array<string, mixed>
     */
    public function for(Order $order, array $access): array
    {
        $order->loadMissing('items.product');
        /** @var Collection<int, OrderItem> $lines */
        $lines = $access['lines'];
        $returns = $access['returns'] instanceof Collection ? $access['returns'] : collect($access['returns']);
        $returnsByLine = $returns->keyBy('order_item_id');
        $payment = $this->paymentSummary($order);

        $parcels = $access['groups']->map(fn (OrderGroup $g) => $this->parcel($order, $g, $lines->where('order_group_id', $g->id)->values(), $returnsByLine))->values();

        // Lines written before parcels existed have no group; they are shown together so nothing disappears.
        $loose = $lines->whereNull('order_group_id')->values();
        $looseParcel = $loose->isEmpty() ? null : [
            'group' => null, 'store_name' => $loose->first()->displayStoreName(), 'status' => OrderStatus::derive($loose->pluck('status')),
            'method' => null, 'estimate' => null, 'collection' => false, 'delivery_cents' => 0,
            'lines' => $loose->map(fn (OrderItem $l) => $this->line($order, $l, $returnsByLine->get($l->id)))->all(), 'shipments' => [],
        ];

        return [
            'order' => $order,
            'access' => $access,
            'status' => $this->status($order, $lines),
            'steps' => $this->steps($order, $lines, $access['groups']),
            'parcels' => $looseParcel ? $parcels->push($looseParcel) : $parcels,
            'payment' => $payment,
            'payable' => $order->isPayable(),
            'holdUntil' => $this->hold->until($order),
            'refunds' => $access['refunds'],
            'returns' => $returns,
            'history' => $access['history'],
            'totals' => $this->totals($order),
            'shipping' => $access['shipping'],
            'returnWindowDays' => (int) config('emall.returns.window_days', 7),
        ];
    }

    /** The one status word + shape for the header. @return array{key: string, kind: string} */
    public function status(Order $order, Collection $lines): array
    {
        if ($order->status === OrderStatus::Cancelled) {
            return ['key' => 'cancelled'];
        }

        if ($order->isAwaitingPayment()) {
            return ['key' => $order->payment_status === PaymentStatus::Failed ? 'payment_failed' : 'awaiting_payment'];
        }

        return ['key' => self::keyFor(OrderStatus::derive($lines->pluck('status')), $order)];
    }

    /** The status vocabulary key (App\Support\Status) for a line or parcel status of this order. */
    public static function keyFor(OrderStatus $status, Order $order): string
    {
        return match ($status) {
            OrderStatus::Pending => $order->requires_payment ? ($order->payment_status->isSettled() ? 'paid' : 'awaiting_payment') : 'pending',
            OrderStatus::Processing => 'packing',
            default => $status->value,
        };
    }

    /**
     * The waybill: placed, paid, packing, sent, arrived, each with the real time of the first line to get there, or "Not yet".
     *
     * @return list<array<string, mixed>>
     */
    private function steps(Order $order, Collection $lines, Collection $groups): array
    {
        $live = $lines->reject(fn (OrderItem $l) => $l->status === OrderStatus::Cancelled);
        $allCollection = $groups->isNotEmpty() && $groups->every(fn (OrderGroup $g) => $g->isCollection());
        $steps = [['key' => 'paid', 'label' => 'Order placed', 'at' => $order->created_at, 'state' => 'done']];

        if ($order->status === OrderStatus::Cancelled) {
            $steps[] = ['key' => 'cancelled', 'label' => 'Cancelled', 'at' => $lines->pluck('cancelled_at')->filter()->max() ?? $order->updated_at, 'state' => 'stop',
                'note' => $order->requires_payment && ! $order->payment_status->isSettled() ? 'Not paid in time, or cancelled before payment. Nothing was charged.' : null];

            return $steps;
        }

        if ($order->requires_payment) {
            $steps[] = ['key' => 'paid', 'label' => $order->payment_status->isSettled() ? 'Payment received' : 'Payment', 'at' => $order->paid_at, 'state' => $order->payment_status->isSettled() ? 'done' : 'current',
                'note' => $order->payment_status->isSettled() ? null : 'Waiting for Paystack to confirm payment.'];
        }

        $anyCollection = $groups->contains(fn (OrderGroup $g) => $g->isCollection());
        $settled = ! $order->requires_payment || $order->payment_status->isSettled();

        $defs = [
            ['key' => 'packing', 'label' => 'Packing', 'cols' => ['processing_at'], 'also' => ['processing', 'shipped', 'ready_for_collection', 'delivered', 'collected', 'refunded']],
            $allCollection
                ? ['key' => 'shipped', 'label' => 'Ready for collection', 'cols' => ['ready_at'], 'also' => ['ready_for_collection', 'delivered', 'collected', 'refunded']]
                : ['key' => 'shipped', 'label' => $anyCollection ? 'Sent or ready for collection' : 'Sent', 'cols' => $anyCollection ? ['shipped_at', 'ready_at'] : ['shipped_at'], 'also' => ['shipped', 'ready_for_collection', 'delivered', 'collected', 'refunded']],
            ['key' => 'delivered', 'label' => $allCollection ? 'Collected' : ($anyCollection ? 'Delivered or collected' : 'Delivered'), 'cols' => ['delivered_at', 'collected_at'], 'also' => ['delivered', 'collected', 'refunded']],
        ];

        $currentSet = false;

        foreach ($defs as $d) {
            $statuses = array_map(fn ($s) => OrderStatus::from($s), $d['also']);
            // "Packing" is reached by any later step too, even when the seller skipped the packing click (D-15).
            $done = $live->isNotEmpty() && $live->every(fn (OrderItem $l) => in_array($l->status, $statuses, true));
            $times = $live->flatMap(fn (OrderItem $l) => collect($d['cols'])->map(fn ($c) => $l->{$c}))->filter();
            $at = $times->min();
            $partial = ! $done && $at !== null;

            $state = $done ? 'done' : (! $currentSet && $settled ? 'current' : 'todo');
            $currentSet = $currentSet || $state === 'current';

            $steps[] = ['key' => $d['key'], 'label' => $d['label'], 'at' => $at, 'state' => $state,
                'note' => $partial ? 'Some items have reached this step.' : null];
        }

        if ($live->isNotEmpty() && $live->every(fn (OrderItem $l) => $l->status === OrderStatus::Refunded)) {
            $steps[] = ['key' => 'refunded', 'label' => 'Refunded', 'at' => $live->pluck('refunded_at')->filter()->max(), 'state' => 'stop'];
        }

        return $steps;
    }

    /** @return array<string, mixed> */
    private function parcel(Order $order, OrderGroup $group, Collection $lines, Collection $returnsByLine): array
    {
        $shipments = $group->shipments->map(fn (Shipment $s) => [
            'shipment' => $s,
            'carrier' => $s->carrier_name ?: $this->carriers->name($s->carrier_key),
            'tracking_number' => $s->tracking_number,
            // The link comes from the allowlist and the carrier's number pattern only.
            'tracking_url' => $this->carriers->trackingUrl($s->carrier_key, $s->tracking_number),
            'events' => $s->events,
            'item_ids' => $s->items->pluck('id')->all(),
        ])->all();

        $sellerEstimate = $group->shipments->first(fn (Shipment $s) => $s->estimated_from || $s->estimated_to);

        return [
            'group' => $group,
            'store_name' => $group->store_name,
            'store_id' => $group->store_id,
            'status' => OrderStatus::derive($lines->pluck('status')),
            'method' => $group->method_name,
            'collection' => $group->isCollection(),
            'delivery_cents' => (int) $group->delivery_cents,
            'estimate' => $this->estimate($group, $sellerEstimate),
            'lines' => $lines->map(fn (OrderItem $l) => $this->line($order, $l, $returnsByLine->get($l->id)))->all(),
            'shipments' => $shipments,
        ];
    }

    /** @return array{text: string, source: string}|null */
    private function estimate(OrderGroup $group, ?Shipment $shipment): ?array
    {
        if ($shipment !== null && ($shipment->estimated_from || $shipment->estimated_to)) {
            return ['text' => $this->range($shipment->estimated_from, $shipment->estimated_to), 'source' => 'Estimate entered by the seller when they sent the parcel. Not a promise.'];
        }

        if ($group->estimated_from || $group->estimated_to) {
            return ['text' => $this->range($group->estimated_from, $group->estimated_to), 'source' => "Estimate from the seller's handling time and delivery method, worked out when you ordered. Not a promise."];
        }

        return null;
    }

    private function range(?Carbon $from, ?Carbon $to): string
    {
        $from ??= $to;
        $to ??= $from;

        if ($from === null) {
            return '';
        }

        return $from->isSameDay($to) ? $from->format('D j M') : ($from->format('M') === $to->format('M') ? $from->format('j').' to '.$to->format('j M') : $from->format('j M').' to '.$to->format('j M'));
    }

    /** @return array<string, mixed> */
    private function line(Order $order, OrderItem $line, ?ReturnRequest $return): array
    {
        $since = $line->status === OrderStatus::Collected ? ($line->collected_at ?? $line->delivered_at) : ($line->delivered_at ?? $line->collected_at);
        $window = (int) config('emall.returns.window_days', 7);
        $why = $line->status->isCompleted() || $line->status === OrderStatus::Refunded ? $this->returns->ineligibleBecause($line) : null;
        $settled = ! $order->requires_payment || $order->payment_status->isSettled();

        return [
            'item' => $line,
            'name' => $line->displayName(),
            'store' => $line->displayStoreName(),
            'linkable' => $line->product && ! $line->product->trashed() && $line->product->is_active && $line->product->status === 'approved',
            'cancelled' => $line->isCancelled(),
            'can_cancel' => $line->status->isCancellable(),
            'can_confirm' => $settled && $line->status->buyerTransitions() !== [],
            'confirm_label' => $line->status === OrderStatus::ReadyForCollection ? 'I have collected it' : 'I have received it',
            'return' => $return,
            'can_return' => $line->status->isCompleted() && $why === null,
            'return_note' => $line->status->isCompleted() && $return === null ? $why : null,
            'return_until' => $since !== null && $line->status->isCompleted() ? $since->copy()->addDays($window) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentSummary(Order $order): array
    {
        if (! $order->requires_payment) {
            return ['required' => false, 'attempt' => null, 'paid' => null, 'channel' => null, 'reference' => null, 'open' => false];
        }

        $payments = $order->payments()->where('provider', 'paystack')->orderByDesc('id')->get();
        /** @var Payment|null $paid */
        $paid = $payments->first(fn (Payment $p) => $p->status === PaymentAttemptStatus::Success);
        $latest = $paid ?? $payments->first();

        return [
            'required' => true,
            'attempt' => $latest,
            'paid' => $paid,
            'channel' => is_array($paid?->raw_summary) ? ($paid->raw_summary['channel'] ?? null) : null,
            'reference' => $latest?->reference,
            'open' => $payments->contains(fn (Payment $p) => $p->status === PaymentAttemptStatus::Pending),
            'needs_review' => $payments->contains(fn (Payment $p) => $p->status === PaymentAttemptStatus::NeedsReview),
            'attempts' => $payments->count(),
        ];
    }

    /** @return array<string, int> */
    private function totals(Order $order): array
    {
        $vat = $order->vat_total !== null ? (int) round(((float) $order->vat_total) * 100) : 0;

        return [
            'subtotal' => (int) round(((float) $order->subtotal) * 100),
            'discount' => (int) round(((float) $order->discount_amount) * 100),
            'delivery' => $order->deliveryCents(),
            'vat' => $vat,
            'total' => $order->totalCents(),
        ];
    }
}
