<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One seller's share of an order (a "parcel"): its delivery method and cost, the VAT part, the delivery estimate
 * and, for the ledger, the unit earnings are recorded against. delivery_cents is a snapshot taken at checkout and
 * never recomputed; it counts toward the order total only while the group still has a live line.
 */
class OrderGroup extends Model
{
    protected $fillable = [
        'order_id', 'store_id', 'seller_id', 'store_name', 'shipping_method_id', 'method_name', 'method_type',
        'delivery_cents', 'vat_cents', 'vat_registered', 'handling_days', 'window_min_days', 'window_max_days',
        'estimated_from', 'estimated_to', 'status', 'shipped_at', 'delivered_at',
    ];

    protected $casts = [
        'delivery_cents' => 'integer',
        'vat_cents' => 'integer',
        'vat_registered' => 'boolean',
        'estimated_from' => 'date',
        'estimated_to' => 'date',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isCollection(): bool
    {
        return $this->method_type === 'collection';
    }
}
