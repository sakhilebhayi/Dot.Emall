<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

/**
 * The seller dashboard's numbers. This used to be ~50 lines inline in
 * routes/web.php and attributed a mixed-seller order's WHOLE total to every
 * seller with an item in it.
 *
 * The unit of account here is the seller's own order LINE:
 *  - revenue = sum(line total - line promo share) over this seller's
 *    DELIVERED, non-cancelled lines. Another seller's lines, the order's
 *    grand total and unpaid/undelivered lines never count.
 *  - "orders" counts distinct orders holding at least one non-cancelled line
 *    of this seller; "awaiting fulfilment" counts orders where one of this
 *    seller's lines is still pending or processing.
 * Lines are matched on the seller_id snapshot taken at checkout, so
 * archiving or deleting a listing never moves history between sellers or
 * out of the numbers.
 *
 * "Revenue" is EARNED, not paid out. Paystack collects the buyer's money
 * into the platform's own account; seller payouts do not exist yet, so a
 * figure here never means a seller has been paid. Only lines of orders a
 * seller may fulfil count: orders paid through Paystack, and orders placed
 * before Paystack was configured (which never required payment). Lines of an
 * order still awaiting payment are neither revenue nor "to fulfil".
 */
class SellerDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function summary(User $seller): array
    {
        $store = $seller->store;
        $stores = $store ? collect([$store->loadCount('products')]) : collect();

        $productIds = $store
            ? Product::query()->where('store_id', $store->id)->pluck('id')
            : collect();

        $lines = fn () => OrderItem::query()->where('seller_id', $seller->id);
        $live = fn () => $lines()->where('status', '!=', OrderStatus::Cancelled->value);
        $fulfillable = fn () => $lines()->whereHas('order', fn ($q) => $q->fulfillable());

        $revenueCents = $fulfillable()
            ->where('status', OrderStatus::Delivered->value)
            ->get(['total', 'discount_amount'])
            ->sum(fn (OrderItem $line) => $line->netCents());

        $recentOrderIds = $live()
            ->selectRaw('order_id, MAX(id) as last_line')
            ->groupBy('order_id')
            ->orderByDesc('last_line')
            ->limit(8)
            ->pluck('order_id');

        $recent = Order::query()
            ->whereIn('id', $recentOrderIds)
            ->with(['user:id,name', 'items' => fn ($q) => $q->where('seller_id', $seller->id)])
            ->get()
            ->sortByDesc('id')
            ->map(function (Order $order) {
                $lines = $order->items;
                $needsShipping = $order->isFulfillable() && $lines->contains(fn (OrderItem $l) => $l->status->needsShipping());

                return [
                    'order' => $order,
                    'status' => OrderStatus::derive($lines->pluck('status')),
                    'awaitingPayment' => $order->isAwaitingPayment(),
                    'lineCount' => $lines->reject(fn (OrderItem $l) => $l->isCancelled())->count(),
                    'netCents' => $lines->reject(fn (OrderItem $l) => $l->isCancelled())->sum(fn (OrderItem $l) => $l->netCents()),
                    // Same rule as the order page: the buyer is named to a
                    // seller only while something of theirs is left to ship.
                    'buyerName' => $needsShipping ? ($order->shipping_name ?: $order->user?->name) : null,
                ];
            })
            ->values();

        return [
            'stores' => $stores,
            'totalProducts' => $productIds->count(),
            'totalOrders' => $live()->distinct()->count('order_id'),
            'awaitingFulfilment' => $fulfillable()
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
                ->distinct()->count('order_id'),
            'awaitingPayment' => $live()
                ->whereHas('order', fn ($q) => $q->where('requires_payment', true)
                    ->whereNotIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value]))
                ->distinct()->count('order_id'),
            'revenueCents' => $revenueCents,
            'recentOrders' => $recent,
            'totalReviews' => Review::query()->whereIn('product_id', $productIds)->count(),
            'avgRating' => round((float) Review::query()->whereIn('product_id', $productIds)->avg('rating'), 1),
            'lowStockCount' => Product::query()->whereIn('id', $productIds)->where('is_active', true)->where('stock', '<', 5)->count(),
            'myOrdersCount' => Order::query()->where('user_id', $seller->id)->count(),
        ];
    }
}
