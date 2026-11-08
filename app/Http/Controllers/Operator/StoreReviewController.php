<?php

namespace App\Http\Controllers\Operator;

use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\ContentReport;
use App\Models\Store;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\OperatorDecisions;
use App\Services\Stores\StoreOnboarding;
use App\Services\Stores\StoreTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Store review: the queue of stores waiting to open, every store with its status, and approve, send back, suspend or reinstate with a reason. */
class StoreReviewController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), array_column(StoreStatus::cases(), 'value'), true) ? $request->query('status') : StoreStatus::PendingReview->value;
        $q = trim((string) $request->query('q', ''));

        $stores = Store::query()->with('owner:id,name,email')->withCount('products')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))
            ->when($status === StoreStatus::PendingReview->value, fn ($w) => $w->orderBy('submitted_at'), fn ($w) => $w->latest('id'))
            ->paginate(20)->withQueryString();

        return view('operator.stores.index', [
            'stores' => $stores,
            'status' => $status,
            'counts' => Store::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'q' => $q,
        ]);
    }

    public function show(Store $store, OperatorAudit $audit, StoreOnboarding $onboarding): View
    {
        $store->load('owner:id,name,email', 'team:id,name,infodot_team_uuid,infodot_verified_at', 'reviewer:id,name');

        return view('operator.stores.show', [
            'store' => $store,
            'trust' => StoreTrust::for($store),
            'missing' => $onboarding->missingForSubmission($store),
            'listings' => $store->products()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'reports' => ContentReport::query()->where('reportable_type', 'store')->where('reportable_id', $store->id)->where('status', 'open')->count(),
            'history' => $audit->query(subjectType: 'store', subjectId: $store->id)->limit(10)->get(),
        ]);
    }

    public function approve(Request $request, Store $store, OperatorDecisions $decisions): RedirectResponse
    {
        return $this->attempt('store-'.$store->id, fn () => $decisions->approveStore($store, $request->user()), 'Store approved. The seller has been told.');
    }

    public function reject(Request $request, Store $store, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('store-'.$store->id, fn () => $decisions->sendStoreBack($store, $request->user(), $request->input('reason')), 'Store sent back with your reason. The seller has been told.');
    }

    public function suspend(Request $request, Store $store, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('store-'.$store->id, fn () => $decisions->suspendStore($store, $request->user(), $request->input('reason')), 'Store suspended. The seller has been told why.');
    }

    public function reinstate(Request $request, Store $store, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('store-'.$store->id, fn () => $decisions->reinstateStore($store, $request->user(), $request->input('reason')), 'Store reinstated. The seller has been told.');
    }
}
