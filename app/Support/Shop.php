<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The storefront currency and the one way an amount is printed.
 *
 * The format matches Dot.Auction's Auction::money(): the symbol straight
 * against the number, a comma between thousands, a dot before two decimals
 * (R1,234.56). A currency with no symbol in the map below is printed as its
 * ISO code and a space (CHF 1,234.56), which is unambiguous in every locale.
 * A negative amount keeps its sign outside the symbol (-R5.00).
 *
 * Views, notifications and services must call money()/amount(); a test
 * (NoHardCodedCurrencyTest) fails the build if a literal dollar sign is typed
 * into one again.
 */
final class Shop
{
    /** @var array<string, string> */
    private const SYMBOLS = [
        'ZAR' => 'R',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    /**
     * The storefront currency (SHOP_CURRENCY). Throws if the setting is not
     * three uppercase letters, so a bad value can never reach a price.
     */
    public static function currency(): string
    {
        $currency = config('shop.currency');

        if (! is_string($currency) || ! self::isCode($currency)) {
            throw new InvalidArgumentException(sprintf(
                'SHOP_CURRENCY must be an ISO 4217 currency code of three uppercase letters, such as ZAR; got %s. Fix it in .env (or config/shop.php).',
                json_encode($currency),
            ));
        }

        return $currency;
    }

    /**
     * Fails fast at boot when the setting is wrong.
     */
    public static function assertConfigured(): void
    {
        self::currency();
    }

    public static function isCode(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1;
    }

    public static function symbol(?string $currency = null): ?string
    {
        return self::SYMBOLS[self::codeOrDefault($currency)] ?? null;
    }

    /**
     * An amount in integer cents, in $currency (default: the storefront's).
     */
    public static function money(int $cents, ?string $currency = null): string
    {
        $code = self::codeOrDefault($currency);
        $number = number_format(abs($cents) / 100, 2);
        $symbol = self::SYMBOLS[$code] ?? null;

        return ($cents < 0 ? '-' : '').($symbol ?? $code.' ').$number;
    }

    /**
     * A decimal amount as stored (prices, order totals), converted through
     * integer cents so 19.99 never prints as 19.98.
     */
    public static function amount(string|int|float|null $decimal, ?string $currency = null): string
    {
        return self::money(Money::toCents($decimal), $currency);
    }

    private static function codeOrDefault(?string $currency): string
    {
        if ($currency === null) {
            return self::currency();
        }

        if (! self::isCode($currency)) {
            throw new InvalidArgumentException("\"{$currency}\" is not an ISO 4217 currency code (three uppercase letters).");
        }

        return $currency;
    }
}
