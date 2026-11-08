<?php

namespace App\Http\Controllers\Seller;

use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerService;
use App\Services\Seller\SellerExports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** What this store has earned, what is waiting out the return window, what is released, and the fee on every sale. */
class LedgerController extends SellerController
{
    public function index(Request $request, LedgerService $ledger): View
    {
        $store = $this->myStore($request);
        $status = in_array($request->query('status'), ['held', 'released', 'reversed'], true) ? $request->query('status') : null;

        $entries = LedgerEntry::query()->where('store_id', $store->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('group:id,method_name')->latest('id')->paginate(25)->withQueryString();

        // The next release: the earliest held sale with a date, so "released on" is a date the ledger actually holds.
        $next = LedgerEntry::query()->where('store_id', $store->id)->where('kind', 'sale')->where('status', 'held')->whereNotNull('release_at')->min('release_at');

        return view('seller.ledger', [
            'store' => $store,
            'entries' => $entries,
            'status' => $status,
            'balance' => $ledger->balance($request->user()),
            'next' => $next ? Carbon::parse($next) : null,
            'releaseDays' => (int) config('emall.commission.release_after_days', 7),
            'percent' => $ledger->commissionPercent($store->id),
        ]);
    }

    public function export(Request $request, SellerExports $exports): StreamedResponse
    {
        return $exports->ledger($this->myStore($request));
    }
}
