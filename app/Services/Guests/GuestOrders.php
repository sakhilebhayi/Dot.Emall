<?php

namespace App\Services\Guests;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guest orders become the account's when the same, verified e-mail address signs in (D-9). Matching is on the verified
 * address only; an unverified account never inherits anything. The magic link stops working once an order is attached.
 */
class GuestOrders
{
    public function attachTo(User $user): int
    {
        if ($user->email_verified_at === null || blank($user->email)) {
            return 0;
        }

        return DB::transaction(function () use ($user) {
            $ids = Order::query()
                ->whereNull('user_id')
                ->whereRaw('lower(guest_email) = ?', [mb_strtolower($user->email)])
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            return Order::query()->whereIn('id', $ids)->whereNull('user_id')->update([
                'user_id' => $user->id,
                'guest_attached_at' => now(),
                'guest_token_hash' => null,
            ]);
        });
    }
}
