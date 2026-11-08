<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Everything on a shipment is whatever the SELLER entered: carrier, tracking number, the estimate, the events.
 * The tracking link is built only from a configured allowlist (App\Services\Shipping\Carriers); otherwise it is
 * absent and the number is shown as plain text. Nothing here is fetched from a carrier or invented.
 */
class Shipment extends Model
{
    protected $fillable = [
        'order_group_id', 'order_id', 'seller_id', 'carrier_key', 'carrier_name', 'tracking_number', 'tracking_url',
        'shipped_at', 'estimated_from', 'estimated_to', 'delivered_at', 'note',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'estimated_from' => 'date',
        'estimated_to' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class, 'order_group_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(OrderItem::class, 'shipment_items');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
