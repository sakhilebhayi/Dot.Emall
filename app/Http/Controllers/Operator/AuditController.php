<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\OperatorAccessEntry;
use App\Models\User;
use App\Services\Operator\OperatorAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The audit trail and the personal-data access log: read-only, newest first. */
class AuditController extends Controller
{
    public function __invoke(Request $request, OperatorAudit $audit): View
    {
        $tab = $request->query('tab') === 'access' ? 'access' : 'decisions';
        $action = array_key_exists((string) $request->query('action'), OperatorAudit::ACTIONS) || str_ends_with((string) $request->query('action'), '.*') ? (string) $request->query('action') : null;

        return view('operator.audit', [
            'tab' => $tab,
            'action' => $action,
            'entries' => $tab === 'decisions'
                ? $audit->query($action, $request->integer('actor') ?: null)->paginate(30)->withQueryString()
                : OperatorAccessEntry::query()->orderByDesc('id')->paginate(30)->withQueryString(),
            'actions' => OperatorAudit::ACTIONS,
            'actors' => User::query()->where('is_platform_operator', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
