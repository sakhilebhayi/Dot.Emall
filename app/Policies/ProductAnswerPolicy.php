<?php

namespace App\Policies;

use App\Models\ProductAnswer;
use App\Models\User;
use App\Services\Community\StoreMembers;

/**
 * A seller cannot delete or hide an answer once given (so a wrong or embarrassing answer cannot disappear after a
 * buyer relied on it). Only the operator moderates.
 */
class ProductAnswerPolicy
{
    public function __construct(private readonly StoreMembers $members) {}

    public function delete(User $user, ProductAnswer $answer): bool
    {
        return $this->members->isOperator($user);
    }

    public function moderate(User $user, ProductAnswer $answer): bool
    {
        return $this->members->isOperator($user);
    }

    public function report(User $user, ProductAnswer $answer): bool
    {
        return $user->id !== $answer->user_id && $answer->status === 'published';
    }
}
