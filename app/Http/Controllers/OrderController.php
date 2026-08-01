<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Buyer-facing "My Orders" list + detail (platform-loop pass). Before this,
 * a buyer had no way to look up their own past orders anywhere in the
 * application. Authorization delegated to OrderPolicy — see app/Policies/OrderPolicy.php.
 */
class OrderController extends Controller
{
    public function index(): View
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('items.product.store')
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        $order->load('items.product.store');

        return view('orders.show', compact('order'));
    }
}
