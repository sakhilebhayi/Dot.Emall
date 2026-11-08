<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;

/** Collections are curated by the marketplace operator; everyone may see a live one. */
class CollectionPolicy
{
    public function view(?User $user, Collection $collection): bool
    {
        return $collection->isLive() || ($user !== null && (bool) $user->is_platform_operator);
    }

    public function manage(User $user): bool
    {
        return (bool) $user->is_platform_operator;
    }
}
