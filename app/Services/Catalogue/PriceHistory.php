<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The one price history (D-50). Writing: called by ProductVariant's model events, never by hand. Reading: the lowest
 * price a variant had in a window, which the "was" price (ReferencePrice) and the price-drop notice both quote, so
 * the two can never disagree.
 */
class PriceHistory
{
    public function record(ProductVariant $variant, ?string $old, string $new): ProductPriceHistory
    {
        return ProductPriceHistory::create([
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'old_price' => $old,
            'new_price' => $new,
            'actor_id' => Auth::id(),
            'changed_at' => now(),
        ]);
    }

    /**
     * The lowest price in effect at any moment since $since, in cents, from this marketplace's own records. Null when
     * nothing was recorded for the variant. A price counts from the moment it was set until the next change (or now), so
     * a price that was set before the window and still held at its start counts too.
     */
    public function lowestSince(ProductVariant|Product $subject, Carbon $since): ?int
    {
        $variant = $subject instanceof Product ? $this->variantShown($subject) : $subject;

        if ($variant === null) {
            return null;
        }

        $records = ProductPriceHistory::query()->where('variant_id', $variant->id)->orderBy('changed_at')->orderBy('id')->get(['new_price', 'changed_at']);
        $lowest = null;

        foreach ($records as $i => $record) {
            $until = $records[$i + 1]->changed_at ?? null;

            if ($until !== null && $until->lte($since)) {
                continue;
            }

            $cents = Money::toCents($record->new_price);
            $lowest = $lowest === null ? $cents : min($lowest, $cents);
        }

        return $lowest;
    }

    /** The variant whose price the product row shows: its cheapest active one (the only one for a one-variant product). */
    private function variantShown(Product $product): ?ProductVariant
    {
        return ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->orderBy('price')->orderByDesc('is_default')->orderBy('id')->first();
    }
}
