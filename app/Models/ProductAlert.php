<?php

namespace App\Models;

use Database\Factories\ProductAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAlert extends Model
{
    /** @use HasFactory<ProductAlertFactory> */
    use HasFactory;

    public const BACK_IN_STOCK = 'back_in_stock';

    public const PRICE_DROP = 'price_drop';

    protected $fillable = ['user_id', 'product_id', 'variant_id', 'variant_key', 'kind', 'baseline_price', 'target_price', 'last_notified_price', 'active', 'triggered_at'];

    protected $casts = [
        'baseline_price' => 'decimal:2',
        'target_price' => 'decimal:2',
        'last_notified_price' => 'decimal:2',
        'active' => 'boolean',
        'triggered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
