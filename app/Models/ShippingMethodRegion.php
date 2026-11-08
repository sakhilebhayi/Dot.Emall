<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a shipping method delivers. A method with no rows delivers anywhere the seller can reach; with rows it
 * delivers only where one matches (country; optionally a province; optionally a postal-code range). A row may
 * carry its own price for that region.
 */
class ShippingMethodRegion extends Model
{
    protected $fillable = ['shipping_method_id', 'country', 'province', 'postal_from', 'postal_to', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}
