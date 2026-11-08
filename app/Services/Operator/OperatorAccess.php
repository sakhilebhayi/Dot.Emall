<?php

namespace App\Services\Operator;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is a platform operator. Operators are created by `php artisan emall:operator <email>` on the server; this page may also grant the
 * role to a person who already has a verified account, and revoke it, each with a written reason and an audit entry. An operator cannot
 * revoke themselves and the last operator cannot be revoked, so the desk can never be locked out of its own marketplace.
 */
class OperatorAccess
{
    public function __construct(private readonly OperatorAudit $audit) {}

    /** @return Collection<int, User> */
    public function operators(): Collection
    {
        return User::query()->where('is_platform_operator', true)->orderBy('name')->get(['id', 'name', 'email', 'email_verified_at', 'created_at']);
    }

    public function grant(User $target, User $by, ?string $reason): User
    {
        $this->operator($by);
        $reason = $this->reason($reason);

        if ($target->is_platform_operator) {
            throw ValidationException::withMessages(['email' => 'That person is already an operator.']);
        }

        if ($target->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => 'Only a person with a verified e-mail address can be made an operator.']);
        }

        DB::transaction(function () use ($target, $by, $reason) {
            $target->forceFill(['is_platform_operator' => true])->save();
            $this->audit->record($by, 'operator.grant', $target, $reason);
        });

        return $target->refresh();
    }

    public function revoke(User $target, User $by, ?string $reason): User
    {
        $this->operator($by);
        $reason = $this->reason($reason);

        if ($target->id === $by->id) {
            throw ValidationException::withMessages(['reason' => 'You cannot remove your own operator access. Ask another operator.']);
        }

        if (! $target->is_platform_operator) {
            throw ValidationException::withMessages(['reason' => 'That person is not an operator.']);
        }

        DB::transaction(function () use ($target, $by, $reason) {
            // Lock the operator rows (PostgreSQL refuses FOR UPDATE with an aggregate, so the ids are read and counted here).
            if (User::query()->where('is_platform_operator', true)->lockForUpdate()->pluck('id')->count() <= 1) {
                throw ValidationException::withMessages(['reason' => 'The last operator cannot be removed.']);
            }

            $target->forceFill(['is_platform_operator' => false])->save();
            $this->audit->record($by, 'operator.revoke', $target, $reason);
        });

        return $target->refresh();
    }

    private function operator(User $user): void
    {
        if (! $user->is_platform_operator) {
            throw new AuthorizationException('Only the Emall team can do this.');
        }
    }

    private function reason(?string $reason): string
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < OperatorDecisions::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => 'Write why, so the audit trail explains it later.']);
        }

        return mb_substr($reason, 0, 500);
    }
}
