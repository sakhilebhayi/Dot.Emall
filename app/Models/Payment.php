<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Paystack attempt to pay an order. Amount is integer cents in the
 * attempt's own currency; it is what we ASKED Paystack to charge, and a
 * confirmation only counts if it matches.
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id', 'provider', 'reference', 'amount_cents', 'currency', 'status',
        'provider_transaction_id', 'authorization_url', 'paid_at', 'raw_summary',
        'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'status' => PaymentAttemptStatus::class,
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'raw_summary' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
