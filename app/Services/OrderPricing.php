<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;

/**
 * The single place an order's money is worked out -- used by checkout to
 * price a new order and by cancellation to re-price it, so the two can
 * never disagree.
 *
 * Rules:
 *  - Cancelled lines count for nothing: not in the subtotal, not eligible
 *    for the promo, not in anyone's revenue.
 *  - A promo is store-scoped. Its discount applies to the non-cancelled
 *    lines of the promo's own store only, computed from the rules
 *    snapshotted on the order (type / value / store) -- never from the
 *    live promo row, which a seller may have edited or deleted since.
 *  - The order-level discount is then split across those eligible lines
 *    in proportion to their totals (largest remainder, to the cent) and
 *    stored on each line. A seller's revenue is therefore
 *    sum(line total - line discount) over their own lines and always
 *    reconciles with what the buyer owes.
 */
class OrderPricing
{
    /**
     * Recomputes and persists subtotal, discount_amount and total on the
     * order and discount_amount on every line.
     */
    public function apply(Order $order): Order
    {
        $lines = $order->items()->orderBy('id')->get();
        $active = $lines->reject(fn ($line) => $line->status === OrderStatus::Cancelled);

        $subtotalCents = $active->sum(fn ($line) => Money::toCents($line->total));

        $discountCents = 0;
        $eligible = collect();

        if ($order->promo_store_id !== null && $order->promo_discount_type !== null) {
            $eligible = $active->filter(fn ($line) => (int) $line->store_id === (int) $order->promo_store_id);
            $eligibleCents = $eligible->sum(fn ($line) => Money::toCents($line->total));
            $discountCents = Money::discountCents($order->promo_discount_type, $order->promo_discount_value, $eligibleCents);
        }

        $weights = $eligible->mapWithKeys(fn ($line) => [$line->id => Money::toCents($line->total)])->all();
        $shares = Money::allocate($discountCents, $weights);

        foreach ($lines as $line) {
            $shareCents = $shares[$line->id] ?? 0;

            if (Money::toCents($line->discount_amount) !== $shareCents) {
                $line->forceFill(['discount_amount' => Money::fromCents($shareCents)])->save();
            }
        }

        $order->forceFill([
            'subtotal' => Money::fromCents($subtotalCents),
            'discount_amount' => Money::fromCents($discountCents),
            'total' => Money::fromCents($subtotalCents - $discountCents),
        ])->save();

        return $order;
    }

    /**
     * Whether any non-cancelled line still earns the order's promo
     * discount -- i.e. whether the promo is still "in use" by this order.
     */
    public function promoStillApplies(Order $order): bool
    {
        if ($order->promo_store_id === null) {
            return false;
        }

        return $order->items()
            ->where('store_id', $order->promo_store_id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->exists();
    }
}
