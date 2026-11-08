<?php

namespace App\Http\Controllers\Operator;

use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\ReturnRequest;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\OperatorDecisions;
use App\Services\Returns\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Returns that reached the operator: the seller did not answer, or the buyer disputed a decline. Decide with a reason both sides can read. */
class ReturnController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request, ReturnService $returns): View
    {
        $tab = $request->query('tab') === 'settled' ? 'settled' : 'waiting';

        $rows = $tab === 'waiting'
            ? $returns->operatorQueue()->paginate(20)->withQueryString()
            : ReturnRequest::query()->whereNotNull('resolved_by')->with(['item', 'order:id,user_id,guest_email,total,currency', 'seller:id,name'])->latest('responded_at')->latest('id')->paginate(20)->withQueryString();

        return view('operator.returns.index', [
            'tab' => $tab,
            'returns' => $rows,
            'waiting' => ReturnRequest::query()->where('status', ReturnStatus::Escalated->value)->count(),
        ]);
    }

    public function show(ReturnRequest $returnRequest, OperatorAudit $audit): View
    {
        $returnRequest->load(['item.product', 'order', 'seller:id,name,email', 'requester:id,name', 'refund']);

        return view('operator.returns.show', [
            'return' => $returnRequest,
            'history' => $audit->query(subjectType: 'return', subjectId: $returnRequest->id)->limit(10)->get(),
        ]);
    }

    public function settle(Request $request, ReturnRequest $returnRequest, OperatorDecisions $decisions): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,approve_refund,uphold_decline'], 'note' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt('return-'.$returnRequest->id, fn () => $decisions->settleReturn($returnRequest, $request->user(), $data['decision'], $data['note'] ?? null), 'Decision recorded. The buyer and the seller have been told.', route('operator.returns.index'));
    }
}
