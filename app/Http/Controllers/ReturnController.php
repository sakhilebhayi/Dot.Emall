<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Concerns\ResolvesBuyerOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Services\Returns\ReturnPhotos;
use App\Services\Returns\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A buyer's returns (D-6): the list and detail of an account's returns, the request form (account and guest), and the dispute of a decline.
 * The rules (7 days from delivery, one open request per item, a stated reason on a decline, escalation after 10 days) are
 * App\Services\Returns\ReturnService's; this class draws them and carries the buyer's words and photos in.
 */
class ReturnController extends Controller
{
    use ResolvesBuyerOrder;

    public function index(Request $request, ReturnService $returns): View
    {
        $user = $request->user();
        $list = ReturnRequest::query()->where('requester_id', $user->id)->with(['item', 'order'])->latest('id')->paginate(10);

        // Items bought, delivered and still inside the window with nothing open: the ones a return could be asked for today.
        $window = (int) config('emall.returns.window_days', 7);
        $eligible = OrderItem::query()
            ->whereHas('order', fn ($o) => $o->where('user_id', $user->id))
            ->whereIn('status', OrderStatus::completedValues())
            ->where(fn ($w) => $w->where('delivered_at', '>=', now()->subDays($window))->orWhere('collected_at', '>=', now()->subDays($window)))
            ->with('order')->latest('id')->limit(20)->get()
            ->filter(fn (OrderItem $i) => $returns->ineligibleBecause($i) === null)->values();

        return view('returns.index', ['returns' => $list, 'eligible' => $eligible, 'windowDays' => $window]);
    }

    public function show(Request $request, ReturnRequest $return): View
    {
        Gate::authorize('view', $return);
        abort_unless($return->requester_id === $request->user()->id, 404);

        return view('returns.show', $this->detail($return) + ['mode' => 'account']);
    }

    public function create(Request $request, Order $order, OrderItem $item, ReturnService $returns): View|RedirectResponse
    {
        $this->buyerOf($request, $order);

        if (($why = $returns->ineligibleBecause($item)) !== null) {
            return $this->orderPageRoute($request, $order)->with('error', $why);
        }

        return view('returns.create', [
            'order' => $order, 'item' => $item, 'mode' => $this->isGuestRoute($request) ? 'guest' : 'account',
            'reasons' => ReturnReason::cases(), 'maxPhotos' => (int) config('emall.returns.max_photos', 4),
            'windowDays' => (int) config('emall.returns.window_days', 7), 'respondDays' => (int) config('emall.returns.seller_response_days', 10),
        ]);
    }

    public function store(Request $request, Order $order, OrderItem $item, ReturnService $returns, ReturnPhotos $photos): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);
        $data = $request->validate([
            'reason_category' => ['required', Rule::in(array_map(fn ($r) => $r->value, ReturnReason::cases()))],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'],
        ], ['reason_category.required' => 'Choose a reason.', 'reason_category.in' => 'Choose a reason from the list.']);

        $paths = [];

        try {
            $paths = $photos->store($order, (array) $request->file('photos', []));
            $return = $returns->request($item, $buyer, $data['reason_category'], $data['note'] ?? null, $paths);
        } catch (ValidationException $e) {
            $photos->discard($paths);

            return back()->withInput($request->except('photos'))->withErrors($e->errors());
        }

        $respond = (int) config('emall.returns.seller_response_days', 10);
        $flash = "Return requested. The seller has {$respond} days to answer; we will write to you when they do.";

        return $buyer !== null
            ? redirect()->route('account.returns.show', $return)->with('status', $flash)
            : $this->orderPageRoute($request, $order)->with('status', $flash);
    }

    public function escalate(Request $request, ReturnRequest $return, ReturnService $returns): RedirectResponse
    {
        Gate::authorize('escalate', $return);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $returns->escalate($return, $request->user(), $data['note'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('account.returns.show', $return)->with('status', 'Sent to the Emall team. They will read both sides and decide.');
    }

    /** @return array<string, mixed> */
    private function detail(ReturnRequest $return): array
    {
        $return->loadMissing(['item', 'order', 'refund']);

        return [
            'return' => $return,
            'order' => $return->order,
            'item' => $return->item,
            'canEscalate' => $return->status === ReturnStatus::Declined,
            'respondDays' => (int) config('emall.returns.seller_response_days', 10),
        ];
    }
}
