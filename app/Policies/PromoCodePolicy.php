<?php

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PromoCodePolicy
{
    use HandlesAuthorization;

    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $user->id === $promoCode->store->owner_id;
    }
}
