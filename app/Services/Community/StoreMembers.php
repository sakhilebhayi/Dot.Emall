<?php

namespace App\Services\Community;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may speak for a store. Today that is the store's owner and anyone who belongs to a team the owner owns (the
 * Jetstream team the store owner leads, which InfoDot company sync fills with the company's people). When the
 * store-staff workstream lands its own roles, this is the one place to narrow it.
 */
class StoreMembers
{
    public function canSpeakFor(User $user, Store $store): bool
    {
        if ($store->owner_id === $user->id) {
            return true;
        }

        return DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->where('teams.user_id', $store->owner_id)
            ->where('team_user.user_id', $user->id)
            ->exists();
    }

    public function isOperator(User $user): bool
    {
        return (bool) $user->is_platform_operator;
    }
}
