<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Seller\SellerController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderLifecycle;
use App\Services\Seller\SellerParcels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Seller-facing order fulfilment. A seller works with their own PARCELS (their share of an order), never the order as a whole:
 * the list loads only this store's parcels (so another seller's lines and the order total are never even fetched), and status
 * changes go through App\Services\OrderLifecycle so cancelling always restocks and re-prices. One parcel's actions are in
 * Seller\ParcelController.
 */
class SellerOrderController extends SellerController
{
    public function index(Request $request, SellerParcels $parcels): View
    {
        $store = $this->myStore($request);
        $tab = $request->query('tab', 'to_ship');
        $tab = array_key_exists($tab, SellerParcels::TABS) ? $tab : 'to_ship';

        $page = $parcels->query($store, $tab)
            ->with(['order:id,user_id,guest_email,status,payment_status,requires_payment,paid_at,created_at,currency,shipping_name', 'items:id,order_group_id,order_id,product_name,variant_label,quantity,total,discount_amount,status', 'shipments:id,order_group_id,carrier_name,tracking_number'])
            // By the day the order was placed (the date the page prints), oldest first where something must be sent, newest first elsewhere; the id only breaks ties (D-194).
            ->orderBy(Order::query()->select('created_at')->whereColumn('orders.id', 'order_groups.order_id'), $tab === 'to_ship' ? 'asc' : 'desc')
            ->orderBy('id', $tab === 'to_ship' ? 'asc' : 'desc')->paginate(15)->withQueryString();

        return view('seller.orders.index', [
            'store' => $store,
            'tab' => $tab,
            'tabs' => SellerParcels::TABS,
            'counts' => $parcels->counts($store),
            'groups' => $page,
            'shipBy' => fn ($g) => $parcels->shipBy($g),
        ]);
    }

    public function updateItemStatus(OrderItem $orderItem, Request $request, OrderLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('updateStatus', $orderItem);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        try {
            // The lifecycle records who did it, enforces the legal transitions and tells the buyer (an account or a guest).
            $lifecycle->advanceItem($orderItem, OrderStatus::from($validated['status']), $request->user(), null, 'seller');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Order item updated.');
    }
}
