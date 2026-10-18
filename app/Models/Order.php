<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's checkout. Payment is NOT implemented anywhere in Dot.Emall (the
 * provider is undecided), so every order is "pending, unpaid": nothing here
 * records, requires or implies that money has changed hands.
 *
 * The money columns always describe the order *as it stands now*: cancelled
 * lines are excluded from subtotal / discount_amount / total (see
 * App\Services\OrderPricing).
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'subtotal', 'promo_code', 'promo_code_id', 'promo_store_id',
        'promo_discount_type', 'promo_discount_value', 'promo_released_at',
        'discount_amount', 'total', 'shipping_name', 'shipping_address', 'shipping_phone',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'promo_discount_value' => 'decimal:2',
        'promo_released_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * Zero-padded order reference, shown to buyers and sellers alike.
     */
    public function reference(): string
    {
        return '#'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
