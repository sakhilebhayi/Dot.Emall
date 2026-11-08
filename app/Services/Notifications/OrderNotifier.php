<?php

namespace App\Services\Notifications;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\CommerceNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the notices for real commerce events to the right person: the buyer (an account, or a guest by e-mail with
 * their order link), the seller, or the buyer and seller of a return. A notice failing (mail down, a deleted user)
 * is logged and swallowed: it must never undo an order, a payment or a refund.
 */
class OrderNotifier
{
    public function buyer(Order $order): User|AnonymousNotifiable|null
    {
        if ($order->user_id !== null) {
            return $order->user ?? User::query()->find($order->user_id);
        }

        return filled($order->guest_email) ? Notification::route('mail', $order->guest_email) : null;
    }

    public function orderPlaced(Order $order): void
    {
        $this->toBuyer($order, 'order_placed');
    }

    public function orderPaid(Order $order): void
    {
        $this->toBuyer($order, 'order_paid', ['amount_cents' => $order->totalCents()]);
    }

    public function paymentFailed(Order $order): void
    {
        $this->toBuyer($order, 'payment_failed');
    }

    public function orderCancelled(Order $order, ?string $reason = null): void
    {
        $this->toBuyer($order, 'order_cancelled', array_filter(['reason' => $reason]));
    }

    public function refundRecorded(Order $order, int $amountCents): void
    {
        $this->toBuyer($order, 'order_refunded', ['amount_cents' => $amountCents]);
    }

    /**
     * A line moved forward: tell the buyer. Shipping carries the seller's carrier and tracking number.
     */
    public function itemMoved(OrderItem $item, string $to, ?Shipment $shipment = null): void
    {
        $kind = match ($to) {
            'processing' => 'item_packing',
            'shipped' => 'order_shipped',
            'ready_for_collection' => 'order_ready_for_collection',
            'delivered' => 'order_delivered',
            'collected' => 'order_collected',
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $this->toBuyer($item->order, $kind, array_filter([
            'item' => $item->displayName(),
            'order_item_id' => $item->id,
            'carrier' => $shipment?->carrier_name,
            'tracking' => $shipment?->tracking_number,
        ]));
    }

    public function returnRequested(ReturnRequest $return): void
    {
        $this->toSeller($return->seller_id, 'return_requested', $return->order_id, [
            'item' => $return->item->displayName(), 'order_item_id' => $return->order_item_id,
            'reason_label' => $return->reason_category->label(), 'respond_by' => $return->respond_by?->format('j M'),
        ]);
    }

    public function returnDecision(ReturnRequest $return, string $kind): void
    {
        $this->toBuyer($return->order, $kind, array_filter([
            'item' => $return->item->displayName(), 'order_item_id' => $return->order_item_id,
            'reason' => $return->seller_response, 'amount_cents' => $return->refund_cents ?: null,
        ]));
    }

    public function returnEscalated(ReturnRequest $return): void
    {
        $data = ['item' => $return->item->displayName(), 'order_item_id' => $return->order_item_id, 'escalation' => $return->escalation_reason];

        $this->toBuyer($return->order, 'return_escalated', $data);
        $this->toSeller($return->seller_id, 'return_escalated', $return->order_id, $data);
    }

    /**
     * @param  iterable<int, LedgerEntry>  $entries  released for ONE seller
     */
    public function ledgerReleased(int $sellerId, int $orderId, int $netCents): void
    {
        $this->toSeller($sellerId, 'ledger_released', $orderId, ['net_cents' => $netCents, 'url' => route('dashboard')]);
    }

    public function lowStock(ProductVariant $variant, int $stock): void
    {
        $variant->loadMissing('product.store');
        $sellerId = $variant->product?->store?->owner_id;

        if ($sellerId === null) {
            return;
        }

        $name = $variant->product->name.($variant->label() ? " ({$variant->label()})" : '');

        $this->toSeller($sellerId, 'low_stock', 0, [
            'item' => $name, 'stock' => $stock, 'url' => route('seller.products.edit', $variant->product_id),
        ]);
    }

    /** @param array<string, scalar|null> $data */
    private function toBuyer(Order $order, string $kind, array $data = []): void
    {
        $this->safely(function () use ($order, $kind, $data) {
            $buyer = $this->buyer($order);

            if ($buyer !== null) {
                Notification::send($buyer, new CommerceNotification($kind, $order->id, $data));
            }
        }, $kind);
    }

    /** @param array<string, scalar|null> $data */
    private function toSeller(?int $sellerId, string $kind, int $orderId, array $data = []): void
    {
        $this->safely(function () use ($sellerId, $kind, $orderId, $data) {
            $seller = $sellerId !== null ? User::query()->find($sellerId) : null;

            if ($seller !== null) {
                Notification::send($seller, new CommerceNotification($kind, $orderId, $data));
            }
        }, $kind);
    }

    private function safely(callable $send, string $kind): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('A notice could not be sent.', ['kind' => $kind, 'error' => class_basename($e)]);
        }
    }
}
