<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'payment_id', 'order_id', 'amount_cents', 'currency', 'status', 'provider_refund_id',
        'reason', 'last_error', 'attempts', 'submitted_at', 'processed_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'attempts' => 'integer',
        'status' => RefundStatus::class,
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
