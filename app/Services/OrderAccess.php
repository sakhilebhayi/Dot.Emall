<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may see what of an order, decided in one place so the policy, the
 * order page and the seller list cannot drift apart.
 *
 *  - buyer     the order's owner. Sees everything on it.
 *  - operator  platform staff (users.is_platform_operator). Sees everything.
 *  - seller    a user with at least one line on the order. Sees ONLY their
 *              own lines, their own net total, and the buyer's shipping
 *              details only while one of those lines still has to ship.
 *              Never the other sellers' lines, the order total, the promo
 *              code, or the buyer's account.
 *
 * A guest holding the order's magic link is the buyer of that one order
 * (presentGuest); a user who is both buyer and seller of the same order
 * (buying from their own store) is treated as the buyer.
 */
class OrderAccess
{
    public const BUYER = 'buyer';

    public const OPERATOR = 'operator';

    public const SELLER = 'seller';

    public function roleFor(Order $order, User $user): ?string
    {
        if ($order->user_id === $user->id) {
            return self::BUYER;
        }

        if ($user->is_platform_operator) {
            return self::OPERATOR;
        }

        if ($order->items()->where('seller_id', $user->id)->exists()) {
            return self::SELLER;
        }

        return null;
    }

    public const GUEST = 'guest';

    /**
     * Everything the order page may render for this viewer.
     *
     * @return array{role: string, lines: Collection<int, OrderItem>, fullOrder: bool, shipping: ?array{name: string, address: ?string, phone: ?string}, sellerNetCents: int, awaitingPayment: bool, paymentDetail: bool, refunds: Collection, groups: Collection, history: Collection, returns: Collection}
     */
    public function present(Order $order, User $user): array
    {
        $role = $this->roleFor($order, $user);

        abort_if($role === null, 403);

        return $this->build($order, $role, $user);
    }

    /**
     * A guest who followed their order's magic link (GuestOrderAccess) is the order's buyer, for this order only.
     *
     * @return array<string, mixed>
     */
    public function presentGuest(Order $order): array
    {
        abort_unless($order->user_id === null && $order->guest_email !== null, 403);

        return $this->build($order, self::GUEST, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Order $order, string $role, ?User $user): array
    {
        $order->loadMissing('items.product', 'user');

        $fullOrder = $role !== self::SELLER;

        $lines = $fullOrder
            ? $order->items
            : $order->items->where('seller_id', $user->id)->values();

        // A seller is not given the buyer's address for an order the buyer
        // has not paid for: there is nothing to ship yet.
        $mayShip = $fullOrder || ($order->isFulfillable() && $lines->contains(fn (OrderItem $line) => $line->status->needsShipping()));

        $shipping = $mayShip ? [
            'name' => $order->shipping_name ?: ($order->user?->name ?? 'Buyer'),
            'address' => $order->shipping_address,
            'phone' => $order->shipping_phone,
            'details' => $order->shipping_snapshot,
        ] : null;

        // A seller sees their own parcel(s) and shipments only; the buyer and operators see every parcel.
        $groups = $order->groups()->with('shipments.events', 'shipments.items:id')->get();
        $groups = $fullOrder ? $groups : $groups->where('seller_id', $user->id)->values();

        $history = $order->statusHistory()->with('actor:id,name')->get();
        $history = $fullOrder ? $history : $history->filter(fn ($h) => $h->order_item_id === null || $lines->contains('id', $h->order_item_id))->values();

        $returns = ReturnRequest::query()->whereIn('order_item_id', $lines->pluck('id'))->orderBy('id')->get();

        return [
            'role' => $role,
            'lines' => $lines,
            'fullOrder' => $fullOrder,
            'shipping' => $shipping,
            'awaitingPayment' => $order->isAwaitingPayment(),
            // Payment references, attempts and refunds are for the buyer and
            // operators; a seller only ever learns "awaiting payment" or not.
            'paymentDetail' => $fullOrder,
            'refunds' => $fullOrder ? $order->refunds()->orderBy('id')->get() : collect(),
            'sellerNetCents' => $lines->reject(fn (OrderItem $l) => $l->isCancelled())->sum(fn (OrderItem $l) => $l->netCents()),
            'groups' => $groups,
            'history' => $history,
            'returns' => $returns,
        ];
    }
}
