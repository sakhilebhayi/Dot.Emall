<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Buyer-facing "My Orders" list and the order page. The page is also what
 * a seller opens to fulfil their lines, but through App\Services\OrderAccess
 * they receive a trimmed view: their own lines only, never the rest of the
 * order.
 */
class OrderController extends Controller
{
    public function index(): View
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('items')
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    public function show(Order $order, OrderAccess $access): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', [
            'order' => $order,
            'view' => $access->present($order, auth()->user()),
        ]);
    }
}
