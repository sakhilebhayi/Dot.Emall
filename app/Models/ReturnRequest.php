<?php

namespace App\Models;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use Database\Factories\ReturnRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'order_item_id', 'order_id', 'requester_id', 'requester_email', 'seller_id', 'reason_category', 'note',
        'photo_paths', 'status', 'refund_cents', 'delivered_at', 'respond_by', 'responded_at', 'seller_response',
        'received_at', 'restock', 'escalated_at', 'escalation_reason', 'escalation_note', 'resolved_by',
        'resolution_note', 'refunded_at', 'refund_id',
    ];

    protected $casts = [
        'status' => ReturnStatus::class,
        'reason_category' => ReturnReason::class,
        'photo_paths' => 'array',
        'refund_cents' => 'integer',
        'delivered_at' => 'datetime',
        'respond_by' => 'datetime',
        'responded_at' => 'datetime',
        'received_at' => 'datetime',
        'escalated_at' => 'datetime',
        'refunded_at' => 'datetime',
        'restock' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
