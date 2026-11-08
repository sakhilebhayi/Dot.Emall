<?php

namespace App\Models;

use App\Services\Seller\PromoRules;
use App\Support\Money;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id', 'code', 'discount_type', 'discount_value', 'min_spend',
        'max_uses', 'per_user_limit', 'used_count', 'expires_at', 'is_active', 'free_shipping', 'conditions',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'free_shipping' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Why this code cannot be used on a store's part of a cart worth $itemsCents with this buyer, or null when it can
     * (App\Services\Seller\PromoRules holds the rules; the cart preview and checkout both ask it).
     */
    public function refusalFor(int $itemsCents, ?int $userId = null, ?string $guestEmail = null): ?string
    {
        return app(PromoRules::class)->refusal($this, $itemsCents, $userId, $guestEmail);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'promo_code_id');
    }

    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * The discount amount for a given eligible subtotal (the portion of
     * the cart belonging to this code's own store -- never the whole
     * cart). Fixed discounts are capped at the eligible subtotal so a
     * code can never make a store's portion of the order negative.
     */
    public function discountFor(float $eligibleSubtotal): float
    {
        return Money::discountCents($this->discount_type, $this->discount_value, Money::toCents($eligibleSubtotal)) / 100;
    }
}
