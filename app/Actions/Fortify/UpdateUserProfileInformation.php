<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        if ($input['email'] !== $user->email && $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);

            return;
        }

        $changed = mb_strtolower((string) $input['email']) !== mb_strtolower((string) $user->email);

        $user->forceFill(array_filter([
            'name' => $input['name'],
            'email' => $input['email'],
            // D-150: a typed e-mail address is nobody's proof. This app does not send verification mail (the account came from
            // InfoDot, which verified the OLD address), so a new address is simply unverified until InfoDot vouches for it at
            // the next sign-in. Everything that trusts "verified" (guest orders joining an account, InfoDot adopting a local
            // account, making someone an operator) then refuses it. Without this, anyone could type a stranger's address here
            // and inherit that stranger's guest orders, with their delivery name, address and phone, at the next sign-in.
            'email_verified_at' => $changed ? null : false,
        ], fn ($v) => $v !== false))->save();
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
