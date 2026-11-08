<?php

namespace App\Http\Controllers\Seller;

use App\Enums\ReturnStatus;
use App\Models\ReturnRequest;
use App\Services\Returns\ReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Returns asked of this seller. The queue is the seller's own (ReturnService::forSeller); a return that belongs to anyone else
 * answers 404. Every decision goes through ReturnService, which enforces the order of states and the reason on a decline.
 */
class ReturnController extends SellerController
{
    public const TABS = [
        'to_answer' => ['Waiting for you', [ReturnStatus::Requested]],
        'approved' => ['Approved, goods on the way', [ReturnStatus::Approved]],
        'declined' => ['Declined', [ReturnStatus::Declined]],
        'escalated' => ['With the Emall team', [ReturnStatus::Escalated]],
        'done' => ['Refunded or closed', [ReturnStatus::Refunded, ReturnStatus::Received, ReturnStatus::Closed]],
    ];

    public function index(Request $request, ReturnService $returns): View
    {
        $store = $this->myStore($request);
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'to_answer';

        $base = fn () => $returns->forSeller($request->user());
        $counts = collect(self::TABS)->map(fn ($t) => $base()->whereIn('status', array_map(fn ($s) => $s->value, $t[1]))->count())->all();

        $list = $base()->whereIn('status', array_map(fn ($s) => $s->value, self::TABS[$tab][1]))
            ->with(['order:id,currency,created_at', 'item.group:id,order_id'])->reorder()->orderBy($tab === 'to_answer' ? 'respond_by' : 'id', $tab === 'to_answer' ? 'asc' : 'desc')->paginate(15)->withQueryString();

        return view('seller.returns.index', ['store' => $store, 'tab' => $tab, 'tabs' => self::TABS, 'counts' => $counts, 'returns' => $list]);
    }

    public function show(Request $request, ReturnRequest $return): View
    {
        $store = $this->myStore($request);
        $this->own($request, $return);
        $return->load(['item.group', 'order:id,currency,created_at', 'requester:id,name']);

        return view('seller.returns.show', [
            'store' => $store,
            'return' => $return,
            'item' => $return->item,
            'daysLeft' => $return->status === ReturnStatus::Requested && $return->respond_by ? (int) now()->startOfDay()->diffInDays($return->respond_by->copy()->startOfDay(), false) : null,
            'photoCount' => count($this->photoPaths($return)),
        ]);
    }

    public function approve(Request $request, ReturnRequest $return, ReturnService $returns): RedirectResponse
    {
        $this->own($request, $return);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->run($return, fn () => $returns->approve($return, $request->user(), $data['note'] ?? null), 'Return approved. The buyer has been told to send the goods back.');
    }

    public function decline(Request $request, ReturnRequest $return, ReturnService $returns): RedirectResponse
    {
        $this->own($request, $return);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000'], ['reason.required' => 'Tell the buyer why you are declining. They can ask the Emall team to look again.', 'reason.min' => 'Tell the buyer why you are declining. They can ask the Emall team to look again.']);

        return $this->run($return, fn () => $returns->decline($return, $request->user(), $data['reason']), 'Return declined. The buyer has your reason.');
    }

    public function receive(Request $request, ReturnRequest $return, ReturnService $returns): RedirectResponse
    {
        $this->own($request, $return);
        $request->validate(['restock' => 'nullable|boolean']);

        return $this->run($return, fn () => $returns->markReceived($return, $request->user(), $request->boolean('restock')), 'Marked as received. The buyer is refunded'.($request->boolean('restock') ? ' and the item is back in stock.' : '.'));
    }

    /** A photo the buyer attached to the return. Only the seller it was asked of can see it. */
    public function photo(Request $request, ReturnRequest $return, int $index): StreamedResponse
    {
        $this->myStore($request);
        $this->own($request, $return);

        $path = $this->photoPaths($return)[$index] ?? null;
        abort_if($path === null, 404);

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
            }
        }

        abort(404);
    }

    private function own(Request $request, ReturnRequest $return): void
    {
        abort_unless($return->seller_id !== null && $return->seller_id === $request->user()->id, 404);
    }

    /** @return array<int, string> only paths under returns/{order id}/, never anything else on a disk */
    private function photoPaths(ReturnRequest $return): array
    {
        return array_values(array_filter((array) $return->photo_paths, fn ($p) => is_string($p) && str_starts_with($p, 'returns/'.$return->order_id.'/') && ! str_contains($p, '..')));
    }

    private function run(ReturnRequest $return, \Closure $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (AuthorizationException $e) {
            abort(404);
        }

        return redirect()->route('seller.returns.show', $return)->with('status', $done);
    }
}
