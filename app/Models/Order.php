<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's checkout.
 *
 * Payment (Paystack, App\Services\PaymentService) is tracked by
 * requires_payment / payment_status / paid_at. An order placed while Paystack
 * was not configured has requires_payment = false: it is "pending, unpaid" in
 * the old sense, nothing was ever asked of the buyer, and sellers can fulfil
 * it as before. An order that requires payment is not fulfillable by sellers
 * until payment_status says Paystack confirmed the money.
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
        'idempotency_key', 'requires_payment', 'payment_status', 'paid_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'promo_discount_value' => 'decimal:2',
        'promo_released_at' => 'datetime',
        'requires_payment' => 'boolean',
        'payment_status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Whether a seller may pack, ship or deliver this order. Orders that
     * never required payment (placed before Paystack was configured) always
     * can; the rest only once Paystack confirmed payment.
     */
    public function isFulfillable(): bool
    {
        return ! $this->requires_payment || $this->payment_status->isSettled();
    }

    public function isAwaitingPayment(): bool
    {
        return $this->requires_payment && ! $this->payment_status->isSettled();
    }

    /**
     * The buyer can still be asked for money: payment is required, not
     * settled, and the order has not been cancelled out from under it.
     */
    public function isPayable(): bool
    {
        return $this->requires_payment
            && $this->payment_status->isOpen()
            && $this->status !== OrderStatus::Cancelled;
    }

    public function totalCents(): int
    {
        return Money::toCents($this->total);
    }

    /**
     * Orders a seller can act on: no payment needed, or payment confirmed.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeFulfillable(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('orders.requires_payment', false)
                ->orWhereIn('orders.payment_status', [
                    PaymentStatus::Paid->value,
                    PaymentStatus::PartiallyRefunded->value,
                    PaymentStatus::Refunded->value,
                ]);
        });
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
