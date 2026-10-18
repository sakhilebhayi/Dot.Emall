<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    protected $fillable = [
        'source', 'event', 'reference', 'outcome', 'reason', 'amount_cents', 'currency',
        'payment_id', 'order_id', 'summary',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'summary' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The only fields of a Paystack payload we keep: no customer, card,
     * authorization or key material ever reaches the database.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar>
     */
    public static function summarise(array $data): array
    {
        $kept = [];

        foreach (['id', 'status', 'reference', 'transaction_reference', 'amount', 'currency', 'gateway_response', 'channel', 'paid_at'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $kept[$key] = is_string($data[$key]) ? mb_substr($data[$key], 0, 120) : $data[$key];
            }
        }

        return $kept;
    }
}
