<?php

namespace App\Services\Dashboard;

/** "1 parcel", "2 parcels": the one way the dashboards count things in words. */
final class Plural
{
    public static function of(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }

    public static function verb(int $n): string
    {
        return $n === 1 ? 'is' : 'are';
    }
}
