<?php

namespace App\Services;

use App\Enums\OrderStatus;
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
 *   5. the order's own status is re-derived -- 'cancelled' when every line is.
 */
class OrderLifecycle
{
    public function __construct(private readonly OrderPricing $pricing) {}

    /**
     * @throws InvalidArgumentException if the line has shipped or is delivered
     */
    public function cancelItem(OrderItem $item): OrderItem
    {
        return DB::transaction(function () use ($item) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($line->status === OrderStatus::Cancelled) {
                return $line;
            }

            if (! $line->status->isCancellable()) {
                throw new InvalidArgumentException('A line that has shipped or been delivered can no longer be cancelled.');
            }

            $line->update(['status' => OrderStatus::Cancelled]);

            if ($line->product_id !== null) {
                Product::withTrashed()->where('id', $line->product_id)->increment('stock', $line->quantity);
            }

            $this->pricing->apply($order);
            $this->releasePromoIfUnused($order);
            $this->refreshOrderStatus($order);

            return $line;
        });
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
