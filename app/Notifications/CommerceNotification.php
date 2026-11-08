<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\Guests\GuestOrderAccess;
use App\Support\Shop;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * One class for the order, delivery, return and money notices, so the wording of every event lives in copy() and a
 * new event is one entry. $data carries only ids and short facts; the order is re-read when the notice is rendered.
 *
 * kinds: order_placed, order_paid, payment_failed, item_packing, order_shipped, order_ready_for_collection,
 *        order_delivered, order_collected, order_cancelled, order_refunded, return_requested, return_approved,
 *        return_declined, return_received, return_refunded, return_escalated, ledger_released, low_stock
 */
class CommerceNotification extends EmallNotification
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(public string $kind, public int $orderId, public array $data = []) {}

    public function toDatabase(object $notifiable): array
    {
        $order = $this->orderId > 0 ? Order::query()->find($this->orderId) : null;
        [$title, $message, $action] = $this->copy($order);

        $url = null;

        if ($order !== null) {
            $url = $notifiable instanceof AnonymousNotifiable
                ? app(GuestOrderAccess::class)->url($order)
                : route('orders.show', $order->id);
        }

        if (isset($this->data['url'])) {
            $url = (string) $this->data['url'];
        }

        return array_filter([
            'type' => $this->kind,
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'order_id' => $order?->id,
            'order_item_id' => $this->data['order_item_id'] ?? null,
            'url' => $url,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array{0: string, 1: string, 2: string} title, message, action label
     */
    private function copy(?Order $order): array
    {
        $ref = $order?->reference() ?? '';
        $item = (string) ($this->data['item'] ?? 'your item');
        $money = fn (int $cents) => Shop::money($cents, $order?->currencyCode());
        $tracking = (string) ($this->data['tracking'] ?? '');
        $carrier = (string) ($this->data['carrier'] ?? '');

        return match ($this->kind) {
            'order_placed' => ["We have your order {$ref}", $order?->isAwaitingPayment()
                ? "Thanks. Order {$ref} is saved and waiting for payment. Stock is held for you for a short while; if it is not paid it is released and the order is cancelled."
                : "Thanks. Order {$ref} has been placed.", 'View your order'],
            'order_paid' => ["Payment received for {$ref}", "We received your payment of {$money((int) ($this->data['amount_cents'] ?? $order?->totalCents() ?? 0))} for order {$ref}. The seller can now pack it.", 'View your order'],
            'payment_failed' => ["Payment did not go through for {$ref}", "Your payment for order {$ref} was not completed. Nothing was taken. You can try again from the order page while it is still held for you.", 'Try payment again'],
            'item_packing' => ["The seller is packing {$item}", "\"{$item}\" from order {$ref} is being packed by the seller.", 'View your order'],
            'order_shipped' => ["Shipped: {$item}", "\"{$item}\" from order {$ref} has been marked shipped by the seller".($carrier !== '' ? " with {$carrier}" : '').($tracking !== '' ? ", tracking number {$tracking}" : '').'. Tracking details are whatever the seller entered; we do not check them with the carrier.', 'View tracking'],
            'order_ready_for_collection' => ["Ready for collection: {$item}", "\"{$item}\" from order {$ref} is ready for you to collect.", 'View your order'],
            'order_delivered' => ["Delivered: {$item}", "\"{$item}\" from order {$ref} is marked delivered. If anything is wrong you can ask for a return within ".(int) config('emall.returns.window_days', 7).' days from the order page.', 'View your order'],
            'order_collected' => ["Collected: {$item}", "\"{$item}\" from order {$ref} is marked collected.", 'View your order'],
            'order_cancelled' => ["Order {$ref} cancelled", (string) ($this->data['reason'] ?? "Order {$ref} was cancelled and the items went back into stock.").($order?->requires_payment && $order?->paid_at ? ' Any money you paid for it is being refunded.' : ''), 'View your order'],
            'order_refunded' => ["Refund on {$ref}", "A refund of {$money((int) ($this->data['amount_cents'] ?? 0))} for order {$ref} has been recorded. It reaches your bank as Paystack processes it.", 'View your order'],
            'return_requested' => ["Return requested: {$item}", "A buyer asked to return \"{$item}\" from order {$ref} ({$this->reason()}). Reply by {$this->when('respond_by')} or it goes to the Emall team.", 'Answer the request'],
            'return_approved' => ["Return approved: {$item}", "The seller approved your return of \"{$item}\" from order {$ref}. Send it back as they ask; the refund follows when they confirm it arrived.", 'View your return'],
            'return_declined' => ["Return declined: {$item}", "The seller declined your return of \"{$item}\" from order {$ref}. Their reason: ".($this->data['reason'] ?? 'not given').'. You can ask the Emall team to look at it.', 'View your return'],
            'return_received' => ["Return received: {$item}", "The seller confirmed they have \"{$item}\" back from order {$ref}. Your refund is being processed.", 'View your return'],
            'return_refunded' => ["Return refund recorded: {$item}", "A refund of {$money((int) ($this->data['amount_cents'] ?? 0))} for \"{$item}\" has been recorded for order {$ref}.", 'View your order'],
            'return_escalated' => ["Return escalated: {$item}", "The return of \"{$item}\" from order {$ref} was passed to the Emall team ({$this->escalationWords()}).", 'View your return'],
            'ledger_released' => ['Earnings released', "{$money((int) ($this->data['net_cents'] ?? 0))} from order {$ref} is now released to you in your Emall ledger. Payouts are not automatic yet.", 'View your earnings'],
            'low_stock' => ["Low stock: {$item}", "\"{$item}\" is down to {$this->data['stock']} in stock.", 'Update stock'],
            default => ['Update from Dot.Emall', 'There is an update on your order.', 'View on Dot.Emall'],
        };
    }

    private function reason(): string
    {
        return (string) ($this->data['reason_label'] ?? 'no reason category given');
    }

    private function when(string $key): string
    {
        return (string) ($this->data[$key] ?? 'the deadline');
    }

    private function escalationWords(): string
    {
        return ($this->data['escalation'] ?? '') === 'seller_no_response' ? 'the seller did not answer in time' : 'the decline was disputed';
    }
}
