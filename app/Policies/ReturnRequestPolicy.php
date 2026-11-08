<?php

namespace App\Policies;

use App\Models\ReturnRequest;
use App\Models\User;

/**
 * A return is visible to the buyer who asked, the seller it was asked of, and the operators; only the seller (or an operator,
 * once it is escalated) answers it, and only the buyer disputes a decline. The state rules live in ReturnService.
 */
class ReturnRequestPolicy
{
    public function view(User $user, ReturnRequest $return): bool
    {
        return $user->id === $return->requester_id || $user->id === $return->seller_id || (bool) $user->is_platform_operator;
    }

    public function respond(User $user, ReturnRequest $return): bool
    {
        return ($return->seller_id !== null && $user->id === $return->seller_id) || (bool) $user->is_platform_operator;
    }

    public function escalate(User $user, ReturnRequest $return): bool
    {
        return $return->requester_id !== null && $user->id === $return->requester_id;
    }

    public function resolve(User $user, ReturnRequest $return): bool
    {
        return (bool) $user->is_platform_operator;
    }
}
