<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

/** The seller of the parcel manages its shipment; the buyer and operators read it. */
class ShipmentPolicy
{
    public function view(User $user, Shipment $shipment): bool
    {
        return $user->id === $shipment->seller_id
            || (bool) $user->is_platform_operator
            || $shipment->order()->where('user_id', $user->id)->exists();
    }

    public function manage(User $user, Shipment $shipment): bool
    {
        return $shipment->seller_id !== null && $user->id === $shipment->seller_id;
    }
}
