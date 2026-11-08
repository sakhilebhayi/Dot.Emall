<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\SellerOrderNote;
use App\Models\Shipment;
use App\Services\OrderLifecycle;
use App\Services\Seller\SellerParcels;
use App\Services\Shipping\Carriers;
use App\Services\Shipping\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * One parcel: what to pack, shipping it with a carrier and tracking number, the seller's own tracking updates, marking it
 * delivered or collected, cancelling lines with a reason, private notes and the packing slip. A parcel is reached only through
 * the store that owns it (404 for anyone else's, so the URL tells a stranger nothing), and every move goes through the state
 * machine, which refuses an unpaid order, a collection parcel marked shipped and every jump the rules do not allow.
 */
class ParcelController extends SellerController
{
    public function show(Request $request, OrderGroup $group, SellerParcels $parcels, Carriers $carriers): View
    {
        $group = $this->own($request, $group);
        $group->load(['order.user:id,name', 'items.returnRequests', 'shipments.events', 'shipments.items:id']);

        $needsShipping = $group->order->isFulfillable() && $group->items->contains(fn (OrderItem $l) => $l->status->needsShipping());

        return view('seller.orders.show', [
            'group' => $group,
            'order' => $group->order,
            'store' => $group->store,
            'items' => $group->items,
            'needsShipping' => $needsShipping,
            'address' => $needsShipping ? $this->address($group) : null,
            'shipBy' => $parcels->shipBy($group),
            'carriers' => $carriers->options(),
            'notes' => SellerOrderNote::query()->where('order_group_id', $group->id)->with('author:id,name')->latest('id')->get(),
            'history' => OrderStatusHistory::query()->whereIn('order_item_id', $group->items->pluck('id'))->latest('id')->limit(30)->get(),
            'paid' => $group->order->isFulfillable(),
            'ledger' => LedgerEntry::query()->where('order_group_id', $group->id)->orderBy('id')->get(),
        ]);
    }

    public function slip(Request $request, OrderGroup $group): View
    {
        $group = $this->own($request, $group);
        $group->load(['order', 'items', 'store']);
        $needsShipping = $group->order->isFulfillable() && $group->items->contains(fn (OrderItem $l) => $l->status->needsShipping());

        return view('seller.orders.slip', ['group' => $group, 'order' => $group->order, 'store' => $group->store, 'items' => $group->items->reject->isCancelled(), 'address' => $needsShipping ? $this->address($group) : null]);
    }

    /** Start packing: every line still waiting goes to "Packing". */
    public function pack(Request $request, OrderGroup $group, OrderLifecycle $lifecycle): RedirectResponse
    {
        $group = $this->own($request, $group);

        return $this->move($group, [OrderStatus::Pending], OrderStatus::Processing, $request, $lifecycle, 'Marked as packing.');
    }

    public function ready(Request $request, OrderGroup $group, OrderLifecycle $lifecycle): RedirectResponse
    {
        $group = $this->own($request, $group);

        return $this->move($group, [OrderStatus::Pending, OrderStatus::Processing], OrderStatus::ReadyForCollection, $request, $lifecycle, 'Marked as ready for collection. The buyer has been told.');
    }

    /** The buyer has it: shipped lines become delivered, lines waiting for collection become collected (D-5). */
    public function deliver(Request $request, OrderGroup $group, OrderLifecycle $lifecycle): RedirectResponse
    {
        $group = $this->own($request, $group);
        $lines = $group->items()->whereIn('status', [OrderStatus::Shipped->value, OrderStatus::ReadyForCollection->value])->get();

        if ($lines->isEmpty()) {
            return back()->withErrors(['status' => 'Nothing in this parcel has been shipped, so there is nothing to mark delivered yet.']);
        }

        try {
            foreach ($lines as $line) {
                $lifecycle->advanceItem($line, $line->status === OrderStatus::Shipped ? OrderStatus::Delivered : OrderStatus::Collected, $request->user(), null, 'seller');
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Marked as delivered. Your earnings for this parcel are released '.(int) config('emall.commission.release_after_days', 7).' days from now unless a return is open.');
    }

    public function ship(Request $request, OrderGroup $group, ShipmentService $ships): RedirectResponse
    {
        $group = $this->own($request, $group);

        $data = $request->validate([
            'carrier' => 'nullable|string|max:100',
            'carrier_name' => 'nullable|string|max:80',
            'tracking_number' => 'nullable|string|max:80',
            'estimated_from' => 'nullable|date',
            'estimated_to' => 'nullable|date|after_or_equal:estimated_from',
            'note' => 'nullable|string|max:255',
            'order_item_ids' => 'nullable|array',
            'order_item_ids.*' => 'integer',
        ]);

        // "carrier" is a key on the allowlist, or "other" with a name the seller types (no tracking link for those).
        $key = ($data['carrier'] ?? '') === 'other' ? null : ($data['carrier'] ?? null);

        try {
            $ships->ship($group, $request->user(), [
                'carrier_key' => $key,
                'carrier_name' => $data['carrier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'estimated_from' => $data['estimated_from'] ?? null,
                'estimated_to' => $data['estimated_to'] ?? null,
                'note' => $data['note'] ?? null,
                'order_item_ids' => array_map('intval', $data['order_item_ids'] ?? []),
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        return redirect()->route('seller.orders.show', $group)->with('status', 'Shipped. The buyer has been sent the carrier and tracking number.');
    }

    public function tracking(Request $request, Shipment $shipment, ShipmentService $ships): RedirectResponse
    {
        $this->ownShipment($request, $shipment);

        $data = $request->validate([
            'carrier' => 'nullable|string|max:100',
            'carrier_name' => 'nullable|string|max:80',
            'tracking_number' => 'nullable|string|max:80',
        ]);

        try {
            $ships->updateTracking($shipment, $request->user(), [
                'carrier_key' => ($data['carrier'] ?? '') === 'other' ? null : ($data['carrier'] ?? null),
                'carrier_name' => $data['carrier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', 'Tracking details updated.');
    }

    public function event(Request $request, Shipment $shipment, ShipmentService $ships): RedirectResponse
    {
        $this->ownShipment($request, $shipment);

        $data = $request->validate(['description' => 'required|string|max:190', 'location' => 'nullable|string|max:120']);

        try {
            $ships->addEvent($shipment, $request->user(), $data['description'], $data['location'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', 'Update added. The buyer sees it on their order, marked as coming from you.');
    }

    /** Cancel lines with a reason the buyer is told. Restocks, re-prices and refunds through the lifecycle's own rules. */
    public function cancel(Request $request, OrderGroup $group, OrderLifecycle $lifecycle): RedirectResponse
    {
        $group = $this->own($request, $group);

        $data = $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'reason' => 'required|string|min:5|max:255',
        ], ['item_ids.required' => 'Tick the items you are cancelling.', 'item_ids.min' => 'Tick the items you are cancelling.', 'reason.required' => 'Say why, so the buyer understands.', 'reason.min' => 'Say why, so the buyer understands.']);

        $lines = $group->items()->whereIn('id', $data['item_ids'])->get();

        // D-151: an id that is not a line of THIS parcel (a stale page, a hand-made request) used to cancel nothing and still answer
        // "Items cancelled and put back in stock". Say so, and change nothing, unless every ticked line is this parcel's.
        if ($lines->isEmpty() || $lines->count() !== count(array_unique(array_map('intval', $data['item_ids'])))) {
            return back()->withErrors(['item_ids' => 'Some of the items you ticked are not in this parcel, so nothing was cancelled. Reload the page and try again.'])->withInput();
        }

        try {
            foreach ($lines as $line) {
                $lifecycle->cancelItem($line, $request->user(), $data['reason'], 'seller');
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['item_ids' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $lines->count() === 1 ? 'Item cancelled and put back in stock. If the buyer paid, they are refunded.' : 'Items cancelled and put back in stock. If the buyer paid, they are refunded.');
    }

    public function note(Request $request, OrderGroup $group): RedirectResponse
    {
        $group = $this->own($request, $group);
        $data = $request->validate(['body' => 'required|string|max:1000']);

        SellerOrderNote::create(['order_group_id' => $group->id, 'user_id' => $request->user()->id, 'body' => trim($data['body'])]);

        return back()->with('status', 'Note saved. Only you can see it.');
    }

    /**
     * @param  array<int, OrderStatus>  $from
     */
    private function move(OrderGroup $group, array $from, OrderStatus $to, Request $request, OrderLifecycle $lifecycle, string $done): RedirectResponse
    {
        $lines = $group->items()->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, $from))->get();

        if ($lines->isEmpty()) {
            return back()->withErrors(['status' => 'Nothing in this parcel can be moved to "'.$to->label().'" from where it is.']);
        }

        try {
            foreach ($lines as $line) {
                $lifecycle->advanceItem($line, $to, $request->user(), null, 'seller');
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }

    private function own(Request $request, OrderGroup $group): OrderGroup
    {
        $store = $this->myStore($request);

        // 404, not 403: whether somebody else's parcel exists is not for a stranger to learn.
        abort_unless($group->store_id === $store->id && $group->seller_id === $request->user()->id, 404);

        return $group;
    }

    private function ownShipment(Request $request, Shipment $shipment): void
    {
        $this->myStore($request);
        abort_unless(Gate::forUser($request->user())->allows('manage', $shipment), 404);
    }

    /** The delivery address as the buyer typed it at checkout (the order's snapshot), for fulfilment only. @return array{name: string, lines: array<int, string>, phone: ?string, instructions: ?string} */
    private function address(OrderGroup $group): array
    {
        $order = $group->order;
        $s = (array) ($order->shipping_snapshot ?? []);

        $lines = $s !== []
            ? [$s['line1'] ?? null, $s['line2'] ?? null, $s['suburb'] ?? null, trim(($s['city'] ?? '').' '.($s['postal_code'] ?? '')), $s['province'] ?? null]
            : [$order->shipping_address];

        return [
            'name' => (string) ($s['recipient_name'] ?? $order->shipping_name ?? 'The buyer'),
            'lines' => array_values(array_filter(array_map(fn ($l) => $l === null ? null : trim((string) $l), $lines))),
            'phone' => $s['phone'] ?? $order->shipping_phone,
            'instructions' => $s['instructions'] ?? null,
        ];
    }
}
