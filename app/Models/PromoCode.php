<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCode extends Model
{
    protected $fillable = [
        'store_id', 'code', 'discount_type', 'discount_value',
        'max_uses', 'used_count', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
        $discount = $this->discount_type === 'percent'
            ? $eligibleSubtotal * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return round(min($discount, $eligibleSubtotal), 2);
    }
}
