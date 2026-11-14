<?php

namespace App\Services\Dashboard;

use App\Support\Shop;

/**
 * The geometry of the seller's one chart: sales per day (per week from 90 days), bars from zero. A pure function of the figures
 * SellerAnalytics::series() returns, so the page draws nothing the analytics page does not already say. A day with no sale gets no bar
 * (an honest gap on the baseline) and a hit target that says "no sales".
 */
final class SalesChart
{
    public const WIDTH = 600;

    public const HEIGHT = 158;

    public const LEFT = 64;

    public const TOP = 24;

    public const BASE = 128;

    /**
     * @param  list<array{label: string, cents: int}>  $series
     * @return array{bars: list<array<string, mixed>>, max: int, maxLabel: string, first: string, last: string, summary: string, rows: list<array{label: string, amount: string}>, hasSales: bool}
     */
    public static function build(array $series): array
    {
        $peak = 0;
        $peakIndex = null;
        foreach ($series as $i => $point) {
            if ($point['cents'] > $peak) {
                $peak = $point['cents'];
                $peakIndex = $i;
            }
        }

        $n = max(1, count($series));
        $plotW = self::WIDTH - self::LEFT;
        $slot = $plotW / $n;
        $barW = min(11.0, $slot * 0.6);
        $plotH = self::BASE - self::TOP;
        $max = self::niceMax($peak);

        $bars = [];
        $rows = [];
        $withSales = 0;
        foreach ($series as $i => $point) {
            $x = self::LEFT + $i * $slot;
            $cents = (int) $point['cents'];
            $bar = [
                'x' => round($x, 1),
                'w' => round($slot, 1),
                'cents' => $cents,
                'tip' => $point['label'].': '.($cents > 0 ? Shop::money($cents) : 'no sales'),
                'path' => null,
                'peak' => $i === $peakIndex,
                'labelX' => round($x + $slot / 2, 1),
            ];
            if ($cents > 0) {
                $withSales++;
                $h = max(3.0, $cents / $max * $plotH);
                $bx = $x + ($slot - $barW) / 2;
                $top = self::BASE - $h;
                $bar['top'] = round($top, 1);
                $bar['path'] = sprintf('M%.1f %d V%.1f q0 -2 2 -2 h%.1f q2 0 2 2 V%dz', $bx, self::BASE, $top + 2, $barW - 4, self::BASE);
                $rows[] = ['label' => $point['label'], 'amount' => Shop::money($cents)];
            }
            $bars[] = $bar;
        }

        $peakLabel = $peakIndex === null ? null : $series[$peakIndex]['label'];

        return [
            'bars' => $bars,
            'max' => $max,
            'maxLabel' => Shop::money($max),
            'first' => $series[0]['label'] ?? '',
            'last' => $series[count($series) - 1]['label'] ?? '',
            'summary' => $withSales === 0
                ? 'No sales in this period.'
                : ($withSales === 1 ? 'One' : (string) $withSales).' '.($withSales === 1 ? 'day' : 'days').' had sales, the highest '.Shop::money($peak).' ('.$peakLabel.').',
            'rows' => $rows,
            'hasSales' => $withSales > 0,
            'peakLabel' => $peak > 0 ? Shop::money($peak) : null,
        ];
    }

    /** A round ceiling above the peak, in whole currency units (R100, R250, R500, R900...): the axis prints only this and zero. */
    public static function niceMax(int $peakCents): int
    {
        $units = max(1, (int) ceil($peakCents / 100));
        $mag = 10 ** max(0, strlen((string) $units) - 1);
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 9, 10] as $m) {
            if ($m * $mag >= $units) {
                return (int) round($m * $mag) * 100;
            }
        }

        return 10 * $mag * 100;
    }
}
