<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'status', 'subtotal', 'promo_code', 'discount_amount', 'total', 'shipping_address',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Derives the order's overall status from its items rather than
     * letting a single seller's action set it directly -- a mixed-seller
     * order should only read as "delivered" once every seller has
     * delivered their own items, so the dashboard's existing
     * sum(total) where status='delivered' revenue metric stays correct.
     * Call after any OrderItem status change.
     */
    public function recomputeStatusFromItems(): void
    {
        $statuses = $this->items()->pluck('status');

        $status = match (true) {
            $statuses->every(fn ($s) => $s === 'delivered') => 'delivered',
            $statuses->contains('cancelled') && $statuses->filter(fn ($s) => $s !== 'cancelled')->every(fn ($s) => $s === 'delivered') => 'delivered',
            $statuses->every(fn ($s) => in_array($s, ['shipped', 'delivered', 'cancelled'], true)) => 'shipped',
            $statuses->contains(fn ($s) => in_array($s, ['processing', 'shipped', 'delivered'], true)) => 'processing',
            default => 'pending',
        };

        $this->update(['status' => $status]);
    }
}
