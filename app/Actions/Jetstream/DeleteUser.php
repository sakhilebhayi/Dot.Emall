<?php

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Services\Account\AccountDeletionBlocked;
use App\Services\Account\AccountEraser;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;

/**
 * "Delete my account" (D-7, D-98): the person is anonymised and the shop's order and money records stay. See AccountEraser for
 * exactly what is refused, what is removed and what is kept.
 */
class DeleteUser implements DeletesUsers
{
    public function __construct(protected AccountEraser $eraser) {}

    /**
     * @throws ValidationException while the account still has orders, returns, refunds or held earnings that someone is owed
     */
    public function delete(User $user): void
    {
        try {
            $this->eraser->erase($user);
        } catch (AccountDeletionBlocked $e) {
            throw ValidationException::withMessages(['password' => implode(' ', $e->reasons)]);
        }
    }

    /**
     * Every reason this account cannot be deleted right now, in words for the person; empty when it can. The account page reads the
     * same answer, so what it says and what the delete button does cannot disagree.
     *
     * @return array<int, string>
     */
    public function blockers(User $user): array
    {
        return $this->eraser->obstacles($user);
    }

    /** The reasons as one sentence run; null when the account can be deleted. */
    public function blockedReason(User $user): ?string
    {
        $reasons = $this->blockers($user);

        return $reasons === [] ? null : implode(' ', $reasons);
    }
}
