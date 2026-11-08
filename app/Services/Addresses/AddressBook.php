<?php

namespace App\Services\Addresses;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A person's saved addresses. At most one default for shipping and one for billing: setting a default clears the
 * previous one in the same transaction. The first address saved becomes the default shipping address.
 */
class AddressBook
{
    public const MAX_ADDRESSES = 20;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function add(User $user, array $input): Address
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($user, $data) {
            User::query()->lockForUpdate()->findOrFail($user->id);

            if (Address::query()->where('user_id', $user->id)->count() >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages(['line1' => 'You can keep up to '.self::MAX_ADDRESSES.' addresses. Remove one first.']);
            }

            $first = ! Address::query()->where('user_id', $user->id)->exists();
            $address = Address::create(array_merge($data, [
                'user_id' => $user->id,
                'is_default_shipping' => $first || ($data['is_default_shipping'] ?? false),
                'is_default_billing' => (bool) ($data['is_default_billing'] ?? false),
            ]));

            $this->clearOtherDefaults($address);

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Address $address, array $input): Address
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($address, $data) {
            $address->fill($data)->save();
            $this->clearOtherDefaults($address);

            return $address->refresh();
        });
    }

    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default_shipping;
            $userId = $address->user_id;
            $address->delete();

            // Never leave a person with addresses and no default.
            if ($wasDefault) {
                Address::query()->where('user_id', $userId)->orderByDesc('id')->limit(1)->update(['is_default_shipping' => true]);
            }
        });
    }

    public function setDefault(Address $address, string $kind = 'shipping'): Address
    {
        $column = $kind === 'billing' ? 'is_default_billing' : 'is_default_shipping';

        DB::transaction(function () use ($address, $column) {
            Address::query()->where('user_id', $address->user_id)->where('id', '!=', $address->id)->update([$column => false]);
            $address->forceFill([$column => true])->save();
        });

        return $address->refresh();
    }

    public function defaultShipping(User $user): ?Address
    {
        return Address::query()->where('user_id', $user->id)->orderByDesc('is_default_shipping')->orderByDesc('id')->first();
    }

    /**
     * Validates free-form address fields (a guest, or a one-off address at checkout).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(array $input): array
    {
        $input['country'] = strtoupper(trim((string) ($input['country'] ?? 'ZA'))) ?: 'ZA';

        return Validator::make($input, AddressRules::for($input), AddressRules::messages())->validate();
    }

    private function clearOtherDefaults(Address $address): void
    {
        foreach (['is_default_shipping', 'is_default_billing'] as $column) {
            if ($address->{$column}) {
                Address::query()->where('user_id', $address->user_id)->where('id', '!=', $address->id)->where($column, true)->update([$column => false]);
            }
        }
    }
}
