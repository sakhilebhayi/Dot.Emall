<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only price record. The only source of a "was" price (D-10). */
class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    public $timestamps = false;

    protected $fillable = ['product_id', 'variant_id', 'old_price', 'new_price', 'actor_id', 'changed_at'];

    protected $casts = ['old_price' => 'decimal:2', 'new_price' => 'decimal:2', 'changed_at' => 'datetime'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
