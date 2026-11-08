<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Services\Community\StoreRatings;
use App\Services\Discovery\CountedOrders;
use App\Services\Shipping\BusinessDays;
use App\Support\Money;
use App\Support\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A seller's analytics, from their own orders and the events the shop recorded. REAL numbers only (W6, D-101):
 *
 *   Sales            the value of the items on counted order lines placed in the period, after any promo discount, VAT
 *                    included (prices include it), delivery fees left out. A counted line is one of a paid order that is
 *                    not cancelled or refunded (CountedOrders, D-31). Unpaid, failed, cancelled and refunded lines never count.
 *   Orders           distinct orders holding at least one counted line of this store.
 *   Units            the quantity on those lines.
 *   Average order    sales divided by orders; absent when there are no orders.
 *   Views            distinct people per day who opened a listing (the shop keeps a keyed hash, never a name).
 *   Add to cart      distinct people per day who added the listing to a cart.
 *   Orders per view  orders for a listing divided by its views; shown only when it has views.
 *   Shipped on time  parcels shipped within the handling time promised, of the parcels that were due.
 *   Return rate      return requests made in the period divided by items delivered or collected in the period.
 *
 * A figure with nothing behind it is null, and the page says "No data yet": a zero is only ever a counted zero.
 * Every query is scoped to the one store passed in.
 */
class SellerAnalytics
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];

    public function __construct(private readonly CountedOrders $counted, private readonly StoreRatings $ratings) {}

    /**
     * @return array{days: int, label: string, from: Carbon, to: Carbon, prevFrom: Carbon, prevTo: Carbon}
     */
    public function window(int|string|null $days): array
    {
        $days = (int) $days;
        $days = array_key_exists($days, self::PERIODS) ? $days : 30;
        $to = now();
        $from = now()->subDays($days - 1)->startOfDay();

        return ['days' => $days, 'label' => self::PERIODS[$days], 'from' => $from, 'to' => $to, 'prevFrom' => $from->copy()->subDays($days), 'prevTo' => $from->copy()->subSecond()];
    }

    /**
     * @return array{sales_cents: int, orders: int, units: int, aov_cents: ?int, has_data: bool, other_currency_lines: int}
     */
    public function sales(Store $store, Carbon $from, Carbon $to): array
    {
        $row = $this->lines($store, $from, $to)
            ->selectRaw('COALESCE(SUM(CASE WHEN ROUND(oi.total * 100) > ROUND(oi.discount_amount * 100) THEN ROUND(oi.total * 100) - ROUND(oi.discount_amount * 100) ELSE 0 END), 0) as cents, COUNT(DISTINCT oi.order_id) as orders, COALESCE(SUM(oi.quantity), 0) as units')
            ->first();

        $orders = (int) $row->orders;
        $cents = (int) round((float) $row->cents);

        $other = $this->counted->lines()->where('oi.store_id', $store->id)->whereBetween('o.created_at', [$from, $to])->where('o.currency', '!=', Shop::currency())->count();

        return [
            'sales_cents' => $cents,
            'orders' => $orders,
            'units' => (int) $row->units,
            'aov_cents' => $orders > 0 ? (int) round($cents / $orders) : null,
            'has_data' => $orders > 0,
            'other_currency_lines' => $other,
        ];
    }

    /**
     * Sales per day (or per week from 90 days), oldest first, with the days that had no sale included as honest zeros.
     *
     * @return array<int, array{label: string, cents: int}>
     */
    public function series(Store $store, Carbon $from, Carbon $to): array
    {
        $rows = $this->lines($store, $from, $to)
            ->groupBy(DB::raw('date(o.created_at)'))
            ->selectRaw('date(o.created_at) as d, SUM(CASE WHEN ROUND(oi.total * 100) > ROUND(oi.discount_amount * 100) THEN ROUND(oi.total * 100) - ROUND(oi.discount_amount * 100) ELSE 0 END) as cents')
            ->pluck('cents', 'd');

        $weekly = $from->diffInDays($to) > 60;
        $out = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $weekly ? $day->copy()->startOfWeek()->toDateString() : $day->toDateString();
            $out[$key] ??= ['label' => $weekly ? 'Week of '.$day->copy()->startOfWeek()->format('j M') : $day->format('j M'), 'cents' => 0];
            $out[$key]['cents'] += (int) round((float) ($rows[$day->toDateString()] ?? 0));
        }

        return array_values($out);
    }

    /**
     * One row per listing with something to say: views, add to cart, orders, units and sales for the period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function products(Store $store, Carbon $from, Carbon $to): Collection
    {
        $views = $this->events($store, 'product_view', $from, $to);
        $carts = $this->events($store, 'add_to_cart', $from, $to);

        $sold = $this->lines($store, $from, $to)->whereNotNull('oi.product_id')->groupBy('oi.product_id')
            ->selectRaw('oi.product_id as pid, COUNT(DISTINCT oi.order_id) as orders, SUM(oi.quantity) as units, SUM(CASE WHEN ROUND(oi.total * 100) > ROUND(oi.discount_amount * 100) THEN ROUND(oi.total * 100) - ROUND(oi.discount_amount * 100) ELSE 0 END) as cents')
            ->get()->keyBy('pid');

        $ids = collect(array_keys($views))->merge(array_keys($carts))->merge($sold->keys())->unique()->values();

        return Product::withTrashed()->where('store_id', $store->id)->whereIn('id', $ids)->get(['id', 'name', 'sku', 'status', 'is_active'])
            ->map(function (Product $p) use ($views, $carts, $sold) {
                $v = (int) ($views[$p->id] ?? 0);
                $o = (int) ($sold[$p->id]->orders ?? 0);

                return [
                    'product' => $p,
                    'views' => $v,
                    'carts' => (int) ($carts[$p->id] ?? 0),
                    'orders' => $o,
                    'units' => (int) ($sold[$p->id]->units ?? 0),
                    'sales_cents' => (int) round((float) ($sold[$p->id]->cents ?? 0)),
                    'orders_per_view' => $v > 0 ? round($o / $v * 100, 1) : null,
                ];
            })
            ->sortByDesc(fn (array $r) => [$r['sales_cents'], $r['views']])->values();
    }

    /**
     * @return array{views: int, from_search: int, carts: int, has_data: bool}
     */
    public function engagement(Store $store, Carbon $from, Carbon $to): array
    {
        $views = array_sum($this->events($store, 'product_view', $from, $to));
        $carts = array_sum($this->events($store, 'add_to_cart', $from, $to));

        $fromSearch = (int) DB::query()->fromSub(
            DB::table('commerce_events')->where('name', 'product_view')->where('store_id', $store->id)->where('source', 'search')
                ->whereBetween('created_at', [$from, $to])->select('product_id', 'actor_hash', 'day')->distinct(),
            'v'
        )->count();

        return ['views' => $views, 'from_search' => $fromSearch, 'carts' => $carts, 'has_data' => $views + $carts > 0];
    }

    /**
     * Stock on hand, at the store's own selling prices. Listings that are switched off, unapproved or archived do not count.
     *
     * @return array{value_cents: int, units: int, variants: int, low: int, out: int, has_data: bool}
     */
    public function inventory(Store $store): array
    {
        $rows = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.store_id', $store->id)->whereNull('products.deleted_at')
            ->where('product_variants.is_active', true)
            ->get(['product_variants.price', 'product_variants.stock', 'products.low_stock_threshold', 'products.is_active', 'products.status']);

        $live = $rows->filter(fn ($r) => $r->is_active && $r->status === 'approved');

        return [
            'value_cents' => (int) $live->sum(fn ($r) => Money::toCents($r->price) * (int) $r->stock),
            'units' => (int) $live->sum('stock'),
            'variants' => $live->count(),
            'low' => $rows->filter(fn ($r) => $r->stock > 0 && $r->stock <= (int) $r->low_stock_threshold)->count(),
            'out' => $rows->filter(fn ($r) => $r->stock <= 0)->count(),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    /**
     * @return array{delivered: int, requested: int, rate: ?float, refunded_items: int, refunded_cents: int, cancelled_items: int}
     */
    public function returns(Store $store, Carbon $from, Carbon $to): array
    {
        $delivered = (int) DB::table('order_items')->where('store_id', $store->id)->where(function ($q) use ($from, $to) {
            $q->whereBetween('delivered_at', [$from, $to])->orWhereBetween('collected_at', [$from, $to]);
        })->count();

        $requested = ReturnRequest::query()->where('seller_id', $store->owner_id)
            ->whereIn('order_item_id', DB::table('order_items')->where('store_id', $store->id)->select('id'))
            ->whereBetween('created_at', [$from, $to])->count();

        $refunded = DB::table('order_items')->where('store_id', $store->id)->where('status', OrderStatus::Refunded->value)->whereBetween('refunded_at', [$from, $to])
            ->get(['total', 'discount_amount']);

        $cancelled = (int) DB::table('order_items')->where('store_id', $store->id)->where('status', OrderStatus::Cancelled->value)->whereBetween('cancelled_at', [$from, $to])->count();

        return [
            'delivered' => $delivered,
            'requested' => $requested,
            'rate' => $delivered > 0 ? round($requested / $delivered * 100, 1) : null,
            'refunded_items' => $refunded->count(),
            'refunded_cents' => (int) $refunded->sum(fn ($r) => max(0, Money::toCents($r->total) - Money::toCents($r->discount_amount))),
            'cancelled_items' => $cancelled,
        ];
    }

    /**
     * Parcels (not collection) of paid orders placed in the period: shipped within the handling time promised, and the median
     * hours from payment to shipped.
     *
     * @return array{due: int, on_time: int, rate: ?float, median_hours: ?float, shipped: int}
     */
    public function fulfilment(Store $store, Carbon $from, Carbon $to): array
    {
        $groups = OrderGroup::query()
            ->join('orders', 'orders.id', '=', 'order_groups.order_id')
            ->where('order_groups.store_id', $store->id)
            ->where('order_groups.method_type', '!=', 'collection')
            ->where('order_groups.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$from, $to])
            ->where(fn ($q) => $q->where('orders.requires_payment', false)->orWhereNotNull('orders.paid_at'))
            ->orderByDesc('order_groups.id')->limit(500)
            ->get(['order_groups.handling_days', 'order_groups.shipped_at', 'orders.paid_at', 'orders.created_at as order_created_at']);

        $due = 0;
        $onTime = 0;
        $hours = [];

        foreach ($groups as $g) {
            $base = Carbon::parse($g->paid_at ?? $g->order_created_at);
            $deadline = BusinessDays::add($base, (int) $g->handling_days)->endOfDay();
            $shipped = $g->shipped_at ? Carbon::parse($g->shipped_at) : null;

            if ($shipped !== null) {
                $hours[] = max(0, $base->diffInMinutes($shipped, false)) / 60;
            }
            if ($shipped === null && $deadline->isFuture()) {
                continue;
            }
            $due++;
            if ($shipped !== null && $shipped->lte($deadline)) {
                $onTime++;
            }
        }

        sort($hours);
        $n = count($hours);
        $median = $n === 0 ? null : round($n % 2 ? $hours[intdiv($n, 2)] : ($hours[$n / 2 - 1] + $hours[$n / 2]) / 2, 1);

        return ['due' => $due, 'on_time' => $onTime, 'rate' => $due > 0 ? round($onTime / $due * 100, 1) : null, 'median_hours' => $median, 'shipped' => $n];
    }

    /**
     * @return array{count: int, average: ?float, all_time_count: int, responded: int, response_rate: ?float, store_average: ?float, store_minimum: int}
     */
    public function reviews(Store $store, Carbon $from, Carbon $to): array
    {
        $base = fn () => DB::table('reviews')->join('products', 'products.id', '=', 'reviews.product_id')->where('products.store_id', $store->id)->where('reviews.status', 'published');

        $inPeriod = $base()->whereBetween('reviews.created_at', [$from, $to]);
        $count = (clone $inPeriod)->count();
        $avg = $count > 0 ? round((float) (clone $inPeriod)->avg('reviews.rating'), 2) : null;

        $all = $base()->count();
        $responded = $base()->join('review_responses', 'review_responses.review_id', '=', 'reviews.id')->where('review_responses.status', 'published')->count();
        $summary = $this->ratings->summary($store);

        return [
            'count' => $count,
            'average' => $avg,
            'all_time_count' => $all,
            'responded' => $responded,
            'response_rate' => $all > 0 ? round($responded / $all * 100, 1) : null,
            'store_average' => $summary['average'],
            'store_minimum' => $summary['minimum'],
        ];
    }

    /**
     * Everything the analytics page shows, for a period.
     *
     * @return array<string, mixed>
     */
    public function report(Store $store, int|string|null $days): array
    {
        $w = $this->window($days);

        return [
            'window' => $w,
            'sales' => $this->sales($store, $w['from'], $w['to']),
            'previous' => $this->sales($store, $w['prevFrom'], $w['prevTo']),
            'series' => $this->series($store, $w['from'], $w['to']),
            'engagement' => $this->engagement($store, $w['from'], $w['to']),
            'products' => $this->products($store, $w['from'], $w['to']),
            'inventory' => $this->inventory($store),
            'returns' => $this->returns($store, $w['from'], $w['to']),
            'fulfilment' => $this->fulfilment($store, $w['from'], $w['to']),
            'reviews' => $this->reviews($store, $w['from'], $w['to']),
        ];
    }

    /**
     * Counted order lines of this store placed in the window. Aliases: oi (order_items), o (orders).
     */
    private function lines(Store $store, Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        return $this->counted->lines()->where('oi.store_id', $store->id)->where('o.currency', Shop::currency())->whereBetween('o.created_at', [$from, $to]);
    }

    /**
     * Distinct people-days per product for an event name, within the window.
     *
     * @return array<int, int> product id => count
     */
    private function events(Store $store, string $name, Carbon $from, Carbon $to): array
    {
        return DB::query()->fromSub(
            DB::table('commerce_events')->where('name', $name)->where('store_id', $store->id)->whereNotNull('product_id')
                ->whereBetween('created_at', [$from, $to])->select('product_id', 'actor_hash', 'day')->distinct(),
            'v'
        )->groupBy('product_id')->selectRaw('product_id, COUNT(*) as c')->pluck('c', 'product_id')->map(fn ($c) => (int) $c)->all();
    }
}
