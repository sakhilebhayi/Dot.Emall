<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The "was" price (D-10), computed only from this marketplace's own recorded price changes:
 *
 *   - the history for the variant must reach back at least 30 days (it has to START at least 30 days ago, so the
 *     30-day window is fully covered by what we recorded; nothing is assumed about earlier);
 *   - the reference is the LOWEST price that was in effect at any moment of the 30 days before the current price
 *     took effect (so a price that has been the same all month has no "was");
 *   - it is shown only if it is higher than today's price.
 *
 * Seller-typed numbers (compare_price) are never read here. Returns null when there is nothing honest to show.
 */
class ReferencePrice
{
    /**
     * @return array{cents: int, since: Carbon, window_days: int}|null
     */
    public static function for(ProductVariant|Product $subject): ?array
    {
        $variant = $subject instanceof Product
            ? ($subject->defaultVariant ?? $subject->defaultVariant()->first())
            : $subject;

        if ($variant === null) {
            return null;
        }

        $days = (int) config('emall.reference_price.window_days', 30);
        $now = now();
        $windowStart = $now->copy()->subDays($days);

        $records = ProductPriceHistory::query()
            ->where('variant_id', $variant->id)
            ->orderBy('changed_at')->orderBy('id')
            ->get(['id', 'new_price', 'changed_at']);

        if ($records->count() < 2 || $records->first()->changed_at->gt($windowStart)) {
            return null;
        }

        $current = $records->last();

        // History that disagrees with the live price (an update that bypassed the model) proves nothing.
        if (Money::toCents($current->new_price) !== Money::toCents($variant->price)) {
            return null;
        }

        $lowest = null;

        for ($i = 0; $i < $records->count() - 1; $i++) {
            $effectiveUntil = $records[$i + 1]->changed_at;

            if ($effectiveUntil->lte($windowStart)) {
                continue; // this price ended before the window began
            }

            $cents = Money::toCents($records[$i]->new_price);
            $lowest = $lowest === null ? $cents : min($lowest, $cents);
        }

        if ($lowest === null || $lowest <= Money::toCents($variant->price)) {
            return null;
        }

        return ['cents' => $lowest, 'since' => $windowStart, 'window_days' => $days];
    }
}
