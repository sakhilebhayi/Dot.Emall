<?php

namespace App\Services\Shipping;

use Illuminate\Support\Carbon;

/** Adds working days (Monday to Friday; public holidays are not modelled, which is why dates are estimates). */
final class BusinessDays
{
    public static function add(Carbon $from, int $days): Carbon
    {
        $date = $from->copy()->startOfDay();

        while ($days > 0) {
            $date->addDay();

            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }
}
