<?php

namespace App\Support;

/**
 * Prices include VAT (D-3). This is the VAT component already inside a gross amount: gross x rate / (1 + rate),
 * rounded to the cent. Only stores that say they are VAT registered show it.
 */
final class Vat
{
    public static function rate(): float
    {
        return max(0.0, (float) config('emall.vat.rate', 0.15));
    }

    public static function includedIn(int $grossCents): int
    {
        if ($grossCents <= 0) {
            return 0;
        }

        $rate = self::rate();

        return (int) round($grossCents * $rate / (1 + $rate));
    }
}
