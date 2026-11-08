<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\ReconcileRuns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The ledger reconciliation: orders, payments, refunds and the seller ledger compared. Read-only; "run it now" only compares and stores the result. */
class LedgerController extends Controller
{
    public function index(ReconcileRuns $runs): View
    {
        $latest = $runs->latest();

        return view('operator.ledger.index', [
            'latest' => $latest,
            'history' => $runs->history(15),
            'mismatches' => collect($latest?->mismatches ?? []),
        ]);
    }

    public function run(Request $request, ReconcileRuns $runs, OperatorAudit $audit): RedirectResponse
    {
        $run = $runs->run('operator', null, $request->user());
        $audit->record($request->user(), 'reconcile.run', ['type' => 'reconcile', 'id' => $run->id, 'label' => 'Reconciliation #'.$run->id], null, ['checked' => $run->checked, 'mismatches' => $run->mismatch_count]);

        return redirect()->route('operator.ledger.index')->with($run->mismatch_count > 0 ? 'error' : 'status', $run->mismatch_count > 0
            ? "Checked {$run->checked} paid orders: {$run->mismatch_count} do not agree."
            : "Checked {$run->checked} paid orders: everything agrees.");
    }
}
