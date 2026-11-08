<?php

namespace App\Services\Catalogue;

use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;

/** Writes the append-only price record. Called by ProductVariant's model events, never by hand. */
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
}
