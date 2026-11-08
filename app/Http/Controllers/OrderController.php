<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\OrderPage;
use App\Services\OrderAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "My orders" and the order page. A BUYER gets the buyer's page (parcels, waybill, payment, documents, actions); a seller opening the
 * same address gets the trimmed fulfilment view the seller desk uses, and the operator the full one: through
 * App\Services\OrderAccess they only ever receive what their role may see.
 */
class OrderController extends Controller
{
    /** The filters on the list. Keys are what the address carries. */
    public const FILTERS = [
        'all' => 'All orders',
        'awaiting_payment' => 'Awaiting payment',
        'in_progress' => 'On their way',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled or refunded',
    ];

    public function index(Request $request, OrderPage $page): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::FILTERS) ? (string) $request->query('status') : 'all';
        $q = trim((string) $request->query('q', ''));

        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items', 'groups'])
            ->when($filter === 'awaiting_payment', fn ($o) => $o->where('requires_payment', true)->whereIn('payment_status', ['unpaid', 'pending', 'failed'])->where('status', '!=', 'cancelled'))
            ->when($filter === 'in_progress', fn ($o) => $o->whereIn('status', ['pending', 'processing', 'shipped', 'ready_for_collection'])
                ->where(fn ($w) => $w->where('requires_payment', false)->orWhereIn('payment_status', ['paid', 'partially_refunded'])))
            ->when($filter === 'completed', fn ($o) => $o->whereIn('status', ['delivered', 'collected']))
            ->when($filter === 'cancelled', fn ($o) => $o->whereIn('status', ['cancelled', 'refunded']))
            ->when($q !== '', function ($o) use ($q) {
                // An order number is typed as 33, 00033 or #00033; a product name is matched against what was bought.
                $digits = preg_replace('/\D/', '', $q);
                $o->where(fn ($w) => $w->when($digits !== '' && ltrim($q, '#0123456789 ') === '', fn ($x) => $x->orWhere('id', (int) $digits))
                    ->orWhereHas('items', fn ($i) => $i->where('product_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')));
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        $statuses = $orders->getCollection()->mapWithKeys(fn (Order $o) => [$o->id => $page->status($o, $o->items)['key']]);

        return view('orders.index', ['orders' => $orders, 'statuses' => $statuses, 'filters' => self::FILTERS, 'filter' => $filter, 'q' => $q]);
    }

    public function show(Order $order, OrderAccess $access, OrderPage $page): View
    {
        Gate::authorize('view', $order);

        $view = $access->present($order, auth()->user());

        if ($view['role'] === OrderAccess::BUYER) {
            return view('orders.buyer', $page->for($order, $view) + ['mode' => 'account']);
        }

        return view('orders.show', ['order' => $order, 'view' => $view]);
    }
}
