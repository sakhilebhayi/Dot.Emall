<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Operator\OperatorMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Marketplace metrics with their definitions, a period selector, two honest charts and a CSV export. */
class MetricsController extends Controller
{
    public function index(Request $request, OperatorMetrics $metrics): View
    {
        $report = $metrics->report($request->query('period'));

        return view('operator.metrics', [
            'report' => $report,
            'periods' => OperatorMetrics::PERIODS,
            'orderDays' => collect($report['series'])->sum('orders'),
        ]);
    }

    public function export(Request $request, OperatorMetrics $metrics): Response
    {
        $period = $metrics->normalise($request->query('period'));
        $handle = fopen('php://temp', 'r+');

        foreach ($metrics->csvRows($period) as $row) {
            // A cell that starts with = + - @ is read by a spreadsheet as a formula: our definitions never do, but the rule costs nothing.
            fputcsv($handle, array_map(fn ($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'".$c : $c, $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="emall-metrics-'.$period.'-'.now()->format('Y-m-d').'.csv"', 'Cache-Control' => 'no-store']);
    }
}
