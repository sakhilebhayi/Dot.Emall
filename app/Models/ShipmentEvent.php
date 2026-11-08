<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of text the seller typed about a shipment ("Handed to courier", "Out for delivery"). */
class ShipmentEvent extends Model
{
    protected $fillable = ['shipment_id', 'description', 'location', 'occurred_at', 'created_by'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
