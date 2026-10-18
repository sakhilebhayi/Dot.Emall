<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every change to an order line's status goes through here.
 *
 * Cancelling is the interesting one. In a single transaction, holding a
 * row lock on the order so two cancels (a seller and a buyer racing, or a
 * double click) serialise:
 *   1. the line becomes cancelled -- a no-op if it already is, so a replay
 *      can never restock or release a promo twice;
 *   2. its quantity goes back on the product's stock (archived products
 *      included);
 *   3. the order is re-priced without the line (App\Services\OrderPricing);
 *   4. if no non-cancelled line of the promo's store remains, the promo
 *      code's used_count is given back exactly once;
 *   5. the order's own status is re-derived -- 'cancelled' when every line is;
 *   6. if the order was PAID, what the buyer is now owed back is recorded
 *      (App\Services\RefundService::reconcile) and, after the commit, a
 *      refund is requested from Paystack. A Paystack failure never undoes the
 *      cancellation: the debt stays on file as "refund required".
 *
 * Fulfilment (advanceItem) is refused while an order that requires payment
 * is still unpaid; cancelUnpaidOrder() is how an unpaid order that was never
 * paid is given up, reusing the same per-line cancellation.
 */
class OrderLifecycle
{
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @throws InvalidArgumentException if the line has shipped or is delivered
     */
    public function cancelItem(OrderItem $item): OrderItem
    {
        $line = DB::transaction(function () use ($item) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($line->status === OrderStatus::Cancelled) {
                return $line;
            }

            if (! $line->status->isCancellable()) {
                throw new InvalidArgumentException('A line that has shipped or been delivered can no longer be cancelled.');
            }

            $this->cancelLine($order, $line);
            $this->refunds->reconcile($order);

            return $line;
        });

        // After the commit (and never inside a caller's transaction): this
        // talks to Paystack and records, rather than throws, any failure.
        DB::afterCommit(fn () => $this->refunds->submitPending($item->order_id));

        return $line;
    }

    /**
     * Gives up an order that required payment and was never paid: every
     * live line is cancelled through cancelLine() (restock, re-price, promo
     * use back), exactly as if the buyer had cancelled them. Re-checks under
     * the order lock, so a payment that lands first wins and nothing is
     * cancelled.
     *
     * @return bool whether anything was cancelled
     */
    public function cancelUnpaidOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->requires_payment
                || ! $locked->payment_status->isOpen()
                || $locked->status === OrderStatus::Cancelled) {
                return false;
            }

            foreach ($locked->items()->orderBy('id')->lockForUpdate()->get() as $line) {
                if ($line->status === OrderStatus::Cancelled || ! $line->status->isCancellable()) {
                    continue;
                }

                $this->cancelLine($locked, $line);
            }

            // Anything still open at Paystack is moot now. If the buyer pays
            // it anyway the money lands in "needs review", not in this order.
            $locked->payments()
                ->where('status', PaymentAttemptStatus::Pending->value)
                ->update(['status' => PaymentAttemptStatus::Abandoned->value]);

            return true;
        });
    }

    /**
     * The cancellation itself. The caller holds the order row lock and has
     * checked the line is live and cancellable.
     */
    private function cancelLine(Order $order, OrderItem $line): void
    {
        $line->update(['status' => OrderStatus::Cancelled]);

        if ($line->product_id !== null) {
            Product::withTrashed()->where('id', $line->product_id)->increment('stock', $line->quantity);
        }

        $this->pricing->apply($order);
        $this->releasePromoIfUnused($order);
        $this->refreshOrderStatus($order);
    }

    /**
     * Moves a line forward on the fulfilment ladder. Cancelling is routed
     * to cancelItem() so it always restocks and re-prices.
     *
     * @throws InvalidArgumentException when the move is not allowed
     */
    public function advanceItem(OrderItem $item, OrderStatus $to): OrderItem
    {
        if ($to === OrderStatus::Cancelled) {
            return $this->cancelItem($item);
        }

        return DB::transaction(function () use ($item, $to) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $order->isFulfillable()) {
                throw new InvalidArgumentException('This order is awaiting payment, so it cannot be fulfilled yet. Do not ship it until it is paid.');
            }

            if (! in_array($to, $line->status->sellerTransitions(), true)) {
                throw new InvalidArgumentException('That status change is not allowed.');
            }

            $line->update(['status' => $to]);
            $this->refreshOrderStatus($order);

            return $line;
        });
    }

    /**
     * An order's status is derived from its lines, never set directly:
     * cancelled lines are ignored, and an order with nothing but cancelled
     * lines is itself cancelled.
     */
    public function refreshOrderStatus(Order $order): void
    {
        $statuses = $order->items()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $status = OrderStatus::derive($statuses);

        $order->update(['status' => $status]);
    }

    private function releasePromoIfUnused(Order $order): void
    {
        if ($order->promo_code_id === null || $order->promo_released_at !== null) {
            return;
        }

        if ($this->pricing->promoStillApplies($order)) {
            return;
        }

        PromoCode::query()
            ->where('id', $order->promo_code_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');

        $order->update(['promo_released_at' => now()]);
    }
}
