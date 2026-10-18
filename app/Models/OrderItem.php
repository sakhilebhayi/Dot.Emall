<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Money;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order. A checkout can span several sellers' stores, so
 * fulfilment status lives per line and each seller only ever controls
 * their own lines.
 *
 * product_name / store_id / store_name / seller_id / unit_price are a
 * snapshot taken at checkout: the line must keep reading correctly after
 * the product is edited, archived or deleted, or the store is renamed.
 * product_id is a convenience link that goes NULL if the product row is
 * ever hard-deleted; never rely on it for what was bought.
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'store_id', 'store_name', 'seller_id',
        'quantity', 'unit_price', 'total', 'discount_amount', 'status',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'status' => OrderStatus::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Includes archived (soft-deleted) products: an archived listing is
     * gone from the storefront but still part of order history.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function displayName(): string
    {
        return $this->product_name ?? $this->product?->name ?? 'Unavailable product';
    }

    public function displayStoreName(): string
    {
        return $this->store_name ?? $this->store?->name ?? 'Unknown store';
    }

    /**
     * What this line is worth to its seller: the gross line total less the
     * share of the promo discount attributed to it.
     */
    public function netCents(): int
    {
        return max(0, Money::toCents($this->total) - Money::toCents($this->discount_amount));
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }
}
