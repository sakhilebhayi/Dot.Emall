<?php

namespace App\Support;

/**
 * Names for the rate-limit buckets used by checkout.
 *
 * Wrong promo-code guesses are counted per signed-in user AND per IP
 * address, so neither one account hopping addresses nor many accounts on
 * one address can enumerate codes. Only failed guesses are counted; a buyer
 * who types a valid code spends nothing.
 */
final class Throttles
{
    public const PROMO_MAX_FAILURES = 10;

    public const PROMO_WINDOW_SECONDS = 60;

    public static function promoUserKey(int|string $userId): string
    {
        return 'promo-failures:user:'.$userId;
    }

    public static function promoIpKey(?string $ip): string
    {
        return 'promo-failures:ip:'.($ip ?? 'unknown');
    }
}
