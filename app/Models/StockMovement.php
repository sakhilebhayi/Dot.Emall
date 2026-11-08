<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: one row for every change to a variant's stock. Never updated. */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'variant_id', 'product_id', 'delta', 'stock_before', 'stock_after', 'reason', 'actor_id',
        'order_id', 'order_item_id', 'note',
    ];

    protected $casts = ['delta' => 'integer', 'stock_before' => 'integer', 'stock_after' => 'integer', 'created_at' => 'datetime'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
