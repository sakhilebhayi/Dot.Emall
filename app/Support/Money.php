<?php

namespace App\Support;

/**
 * Money arithmetic on integer cents. Order totals, promo discounts and the
 * per-line share of a discount are all computed here so that the pieces
 * always add back up to the whole -- floating-point sums of proportional
 * shares drift by a cent, and a seller's revenue must reconcile with what
 * the buyer was actually charged.
 */
final class Money
{
    public static function toCents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * A promo's discount on an eligible subtotal, in cents. Fixed discounts
     * are capped at the subtotal so a code can never push a store's share
     * of an order below zero.
     */
    public static function discountCents(string $type, float|int|string $value, int $eligibleCents): int
    {
        $discount = $type === 'percent'
            ? (int) round($eligibleCents * ((float) $value) / 100)
            : self::toCents($value);

        return max(0, min($discount, $eligibleCents));
    }

    /**
     * Splits $totalCents across the given weights in proportion, using the
     * largest-remainder method so the shares sum to exactly $totalCents.
     * Ties go to the earlier key, so the result is deterministic.
     *
     * @param  array<int|string, int>  $weights  keyed by whatever the caller uses to identify a line
     * @return array<int|string, int> shares in cents, same keys
     */
    public static function allocate(int $totalCents, array $weights): array
    {
        $shares = array_fill_keys(array_keys($weights), 0);
        $weightSum = array_sum($weights);

        if ($totalCents <= 0 || $weightSum <= 0) {
            return $shares;
        }

        $remainders = [];
        $allocated = 0;

        foreach ($weights as $key => $weight) {
            $exact = $totalCents * $weight / $weightSum;
            $shares[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $shares[$key];
            $allocated += $shares[$key];
        }

        $leftover = $totalCents - $allocated;

        // Stable: equal remainders keep their original (earlier-first) order.
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: array_search($a, array_keys($weights), true) <=> array_search($b, array_keys($weights), true));

        foreach (array_slice($order, 0, $leftover) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }
}
