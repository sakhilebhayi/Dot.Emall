<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /**
     * A checkout can span multiple sellers' stores in one Order (see
     * CartController::checkout()), so fulfillment status lives per item,
     * not on the order as a whole -- each seller only ever controls their
     * own line items. See the add_status_to_order_items_table migration.
     */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    protected $fillable = ['order_id', 'product_id', 'quantity', 'unit_price', 'total', 'status'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
