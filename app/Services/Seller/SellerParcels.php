<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus as S;
use App\Models\OrderGroup;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * A seller's parcels (one seller's share of an order) sorted into the tabs of the orders page. A parcel is in exactly one tab,
 * read from its lines and from whether its order is paid:
 *
 *   unpaid       the order still needs money: nothing may be packed or shipped (OrderLifecycle refuses it too)
 *   to_ship      paid, with a line still to pack or post
 *   on_the_way   nothing left to pack, with a line shipped or waiting for collection
 *   delivered    every live line reached the buyer (delivered, collected or refunded after a return)
 *   cancelled    every line was cancelled
 */
class SellerParcels
{
    public const TABS = [
        'to_ship' => 'To ship',
        'on_the_way' => 'On the way',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'unpaid' => 'Awaiting payment',
    ];

    private const OPEN = [S::Pending, S::Processing];

    private const OUT = [S::Shipped, S::ReadyForCollection];

    public function query(Store $store, string $tab): Builder
    {
        $q = OrderGroup::query()->where('store_id', $store->id);
        $has = fn (array $statuses) => fn ($items) => $items->whereIn('status', array_map(fn (S $s) => $s->value, $statuses));

        return match ($tab) {
            'unpaid' => $q->whereHas('order', fn ($o) => $o->where('requires_payment', true)->whereNotIn('payment_status', ['paid', 'partially_refunded', 'refunded'])->where('status', '!=', 'cancelled'))
                ->whereHas('items', fn ($i) => $i->where('status', '!=', S::Cancelled->value)),
            'to_ship' => $q->whereHas('order', fn ($o) => $o->fulfillable())->whereHas('items', $has(self::OPEN)),
            'on_the_way' => $q->whereHas('order', fn ($o) => $o->fulfillable())->whereDoesntHave('items', $has(self::OPEN))->whereHas('items', $has(self::OUT)),
            'delivered' => $q->whereDoesntHave('items', $has([...self::OPEN, ...self::OUT]))->whereHas('items', $has([S::Delivered, S::Collected, S::Refunded])),
            'cancelled' => $q->whereDoesntHave('items', fn ($i) => $i->where('status', '!=', S::Cancelled->value))->whereHas('items'),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** @return array<string, int> */
    public function counts(Store $store): array
    {
        return collect(self::TABS)->map(fn ($label, $tab) => $this->query($store, $tab)->count())->all();
    }

    /** The day a parcel should be posted by: handling time in working days from payment (or the order, when payment was never required). */
    public function shipBy(OrderGroup $group): ?\Illuminate\Support\Carbon
    {
        $order = $group->order;

        if ($order === null) {
            return null;
        }

        return \App\Services\Shipping\BusinessDays::add(\Illuminate\Support\Carbon::parse($order->paid_at ?? $order->created_at), (int) $group->handling_days);
    }
}
