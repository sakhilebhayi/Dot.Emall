<?php

namespace App\Support;

use App\Models\User;
use App\Services\Discovery\Wishlists;

/**
 * Whether a product is on the signed-in person's wishlist, for the heart on every product card. The person's saved product ids are
 * read once per request (one query) and kept on the request, so a grid of 24 cards costs one query, not 24.
 */
final class WishState
{
    public static function has(User $user, int $productId): bool
    {
        $request = request();
        $key = 'wish.ids.'.$user->id;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, array_flip(app(Wishlists::class)->productIdsFor($user)));
        }

        return isset($request->attributes->get($key)[$productId]);
    }

    /** Forget the per-request memo (after a change inside the same request, and in tests). */
    public static function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'wish.ids.')) {
                request()->attributes->remove($key);
            }
        }
    }
}
