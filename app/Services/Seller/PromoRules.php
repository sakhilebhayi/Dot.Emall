<?php

namespace App\Services\Seller;

use App\Models\Order;
use App\Models\PromoCode;
use App\Support\Money;
use App\Support\Shop;

/**
 * The conditions a promo code can carry beyond "active, not expired, uses left" (W6, D-100): a minimum spend on the store's
 * part of the cart, and a cap on how many times one buyer can use it. The cart preview and checkout both ask refusal(), so
 * the cart and the order never disagree. A guest is recognised by e-mail only at checkout; the cart preview does not know it.
 */
class PromoRules
{
    public function refusal(PromoCode $promo, int $itemsCents, ?int $userId = null, ?string $guestEmail = null): ?string
    {
        if ($promo->min_spend !== null && Money::toCents($promo->min_spend) > 0 && $itemsCents < Money::toCents($promo->min_spend)) {
            return 'Spend at least '.Shop::amount($promo->min_spend).' with this store to use '.$promo->code.'.';
        }

        if ($promo->per_user_limit !== null && ($userId !== null || filled($guestEmail))) {
            $uses = Order::query()
                ->where('promo_code_id', $promo->id)
                ->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $userId !== null
                    ? $q->where('user_id', $userId)
                    : $q->where('guest_email', mb_strtolower((string) $guestEmail)))
                ->count();

            if ($uses >= $promo->per_user_limit) {
                return 'You have already used '.$promo->code.' the most times it allows ('.$promo->per_user_limit.').';
            }
        }

        return null;
    }
}
