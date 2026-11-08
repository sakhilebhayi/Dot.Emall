<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\ContentReport;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\OperatorDecisions;
use App\Services\Operator\OperatorReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Reports on reviews, questions, answers, listings and stores: reasons, how many people, the action and the outcome note, and who keeps being reported. */
class ReportController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request, OperatorReports $reports): View
    {
        $status = in_array($request->query('status'), ['open', 'settled', 'all'], true) ? $request->query('status') : 'open';
        $type = in_array($request->query('type'), ContentReport::TYPES, true) ? $request->query('type') : null;
        $reason = in_array($request->query('reason'), ContentReport::REASONS, true) ? $request->query('reason') : null;
        $view = $request->query('view') === 'repeat' ? 'repeat' : 'items';

        return view('operator.reports.index', [
            'view' => $view,
            'status' => $status,
            'type' => $type,
            'reason' => $reason,
            'groups' => $view === 'items' ? $reports->groups($status, $type, $reason) : collect(),
            'offenders' => $view === 'repeat' ? $reports->repeatOffenders() : collect(),
            'counts' => $reports->counts(),
        ]);
    }

    public function show(string $type, int $id, OperatorReports $reports, OperatorAudit $audit): View
    {
        abort_unless(in_array($type, ContentReport::TYPES, true), 404);
        $rows = $reports->reportsFor($type, $id);
        abort_if($rows->isEmpty(), 404);

        return view('operator.reports.show', [
            'type' => $type,
            'id' => $id,
            'item' => $reports->describe($type, $id),
            'reports' => $rows,
            'open' => $rows->where('status', 'open')->count(),
            'history' => $audit->query(subjectType: $type, subjectId: $id)->limit(10)->get(),
        ]);
    }

    public function resolve(Request $request, string $type, int $id, OperatorDecisions $decisions): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:remove,dismiss'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('report-'.$type.'-'.$id, fn () => $decisions->resolveReport($request->user(), $type, $id, $data['action'], $data['note'] ?? null), $data['action'] === 'remove' ? 'Taken down. The reports are closed with your note.' : 'Reports dismissed. The item stays up.', route('operator.reports'));
    }
}
