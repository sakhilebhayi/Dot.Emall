<?php

namespace App\Services\Account;

use RuntimeException;

/** Thrown by AccountEraser::erase() while the person has something open; the reasons are written for them. */
class AccountDeletionBlocked extends RuntimeException
{
    /** @param array<int, string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
