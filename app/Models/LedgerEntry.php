<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a seller group has earned. Amounts never change after the row is written (a refund or cancellation writes a
 * new, negative `reversal` row); only `status` moves, held to released. There are no payout transfers (D-2).
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'order_group_id', 'order_id', 'seller_id', 'store_id', 'order_item_id', 'reverses_entry_id', 'kind', 'status',
        'currency', 'gross_cents', 'commission_cents', 'net_cents', 'commission_percent', 'reason', 'release_at',
        'released_at', 'idempotency_key',
    ];

    protected $casts = [
        'gross_cents' => 'integer',
        'commission_cents' => 'integer',
        'net_cents' => 'integer',
        'commission_percent' => 'decimal:2',
        'release_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class, 'order_group_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
