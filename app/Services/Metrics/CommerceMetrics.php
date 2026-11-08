<?php

namespace App\Services\Metrics;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Services\Discovery\CountedOrders;
use App\Services\Discovery\Track;
use App\Support\Money;
use App\Support\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace's health numbers, each with a written definition, computed from real rows only. A number whose
 * source has no rows for the period is reported as "no data yet" (value null), never as zero.
 *
 * "A session" here is one anonymous-or-signed-in person on one day (a keyed hash and a date): the events carry no
 * session id on purpose. All windows are [from, to).
 *
 *  discovery_success      searching people (person-days with a search) who then viewed a product the same day
 *  search_to_product      product views that came from a search result / searches
 *  add_to_cart_rate       person-days with an add-to-cart / person-days with a product view
 *  checkout_completion    person-days with a paid order / person-days that started checkout
 *  cart_abandonment       person-days with an add-to-cart and no paid order that day
 *  repeat_purchase_rate   buyers (in the period) who have two or more counted orders ever / buyers in the period
 *  return_rate            return requests / order lines delivered or collected in the period
 *  refund_rate            counted orders with a refund / counted orders
 *  gmv                    sum of totals of counted orders (see CountedOrders), in the storefront currency
 *  aov                    gmv / counted orders
 *  payment_failure_rate   failed payment attempts / attempts that ended (succeeded or failed)
 *  cancellation_rate      orders cancelled / orders placed
 *  seller_activity        active stores with a counted order line / active stores
 */
class CommerceMetrics
{
    public function __construct(private readonly CountedOrders $counted) {}

    /**
     * @return list<Metric>
     */
    public function all(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            $this->discoverySuccess($from, $to),
            $this->searchToProduct($from, $to),
            $this->addToCartRate($from, $to),
            $this->checkoutCompletion($from, $to),
            $this->cartAbandonment($from, $to),
            $this->repeatPurchaseRate($from, $to),
            $this->returnRate($from, $to),
            $this->refundRate($from, $to),
            $this->gmv($from, $to),
            $this->averageOrderValue($from, $to),
            $this->paymentFailureRate($from, $to),
            $this->cancellationRate($from, $to),
            $this->sellerActivity($from, $to),
        ];
    }

    public function discoverySuccess(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Of the people who searched on a day, the share who viewed a product after their first search that day.';

        $first = DB::table('commerce_events')->where('name', Track::SEARCH)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->groupBy('actor_hash', 'day')->selectRaw('actor_hash, day, min(created_at) as first_search');

        $total = (int) DB::query()->fromSub($first, 's')->count();

        $success = (int) DB::query()->fromSub($first, 's')->whereExists(function ($q) {
            $q->select(DB::raw('1'))->from('commerce_events as v')
                ->where('v.name', Track::PRODUCT_VIEW)
                ->whereColumn('v.actor_hash', 's.actor_hash')->whereColumn('v.day', 's.day')->whereColumn('v.created_at', '>=', 's.first_search');
        })->count();

        return Metric::rate('discovery_success', 'Discovery success', $definition, $success, $total);
    }

    public function searchToProduct(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Product views that came from a search result, divided by searches run.';
        $searches = $this->events(Track::SEARCH, $from, $to)->count();
        $fromSearch = $this->events(Track::PRODUCT_VIEW, $from, $to)->where('source', 'search')->count();

        return Metric::rate('search_to_product', 'Search to product', $definition, (int) $fromSearch, (int) $searches);
    }

    public function addToCartRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Person-days with an add to cart, divided by person-days with a product view.';

        return Metric::rate('add_to_cart_rate', 'Add-to-cart rate', $definition, $this->personDays(Track::ADD_TO_CART, $from, $to), $this->personDays(Track::PRODUCT_VIEW, $from, $to));
    }

    public function checkoutCompletion(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Person-days with a paid order, divided by person-days that started checkout.';
        $started = $this->personDays(Track::CHECKOUT_STARTED, $from, $to);

        $completed = (int) DB::query()->fromSub(
            $this->events(Track::CHECKOUT_STARTED, $from, $to)->select('actor_hash', 'day')->distinct(),
            's',
        )->whereExists(function ($q) use ($from, $to) {
            $q->select(DB::raw('1'))->from('commerce_events as p')->where('p.name', Track::ORDER_PAID)
                ->whereColumn('p.actor_hash', 's.actor_hash')->whereColumn('p.day', 's.day')
                ->where('p.created_at', '>=', $from)->where('p.created_at', '<', $to);
        })->count();

        return Metric::rate('checkout_completion', 'Checkout completion', $definition, $completed, $started);
    }

    public function cartAbandonment(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Person-days with an add to cart and no paid order the same day, divided by person-days with an add to cart.';
        $carts = $this->personDays(Track::ADD_TO_CART, $from, $to);

        $abandoned = (int) DB::query()->fromSub(
            $this->events(Track::ADD_TO_CART, $from, $to)->select('actor_hash', 'day')->distinct(),
            's',
        )->whereNotExists(function ($q) use ($from, $to) {
            $q->select(DB::raw('1'))->from('commerce_events as p')->where('p.name', Track::ORDER_PAID)
                ->whereColumn('p.actor_hash', 's.actor_hash')->whereColumn('p.day', 's.day')
                ->where('p.created_at', '>=', $from)->where('p.created_at', '<', $to);
        })->count();

        return Metric::rate('cart_abandonment', 'Cart abandonment', $definition, $abandoned, $carts, 'A person who pays on a later day still counts as abandoned on the day they added to the cart.');
    }

    public function repeatPurchaseRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Of buyers with a counted order in the period, the share with two or more counted orders in all.';

        $buyers = $this->counted->orders($from, $to)->whereNotNull('o.user_id')->distinct()->pluck('o.user_id');

        if ($buyers->isEmpty()) {
            return Metric::none('repeat_purchase_rate', 'Repeat purchase rate', $definition, 'rate', 'No counted orders in the period.');
        }

        $repeat = $this->counted->orders(null, $to)->whereIn('o.user_id', $buyers)->groupBy('o.user_id')->havingRaw('count(*) >= 2')->selectRaw('o.user_id')->get()->count();

        return Metric::rate('repeat_purchase_rate', 'Repeat purchase rate', $definition, $repeat, $buyers->count());
    }

    public function returnRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Return requests opened in the period, divided by order lines delivered or collected in the period.';

        // The lines' own delivered_at / collected_at timestamps (written by the order state machine, D-15), so a line
        // that was edited later does not move into another period. Nothing delivered yet means nothing to divide by.
        $delivered = (int) DB::table('order_items')->where(function ($q) use ($from, $to) {
            $q->where(fn ($d) => $d->where('delivered_at', '>=', $from)->where('delivered_at', '<', $to))
                ->orWhere(fn ($c) => $c->where('collected_at', '>=', $from)->where('collected_at', '<', $to));
        })->count();
        $returns = (int) DB::table('return_requests')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        if ($delivered === 0) {
            return Metric::none('return_rate', 'Return rate', $definition, 'rate', 'No order lines were delivered or collected in this period yet, so there is nothing to compare returns with.');
        }

        return Metric::rate('return_rate', 'Return rate', $definition, $returns, $delivered, $returns === 0 ? 'No returns were requested in this period.' : null);
    }

    public function refundRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Counted orders that have a refund that was not rejected, divided by counted orders.';

        $orders = (int) $this->counted->orders($from, $to)->count();
        $refunded = (int) $this->counted->orders($from, $to)->whereExists(function ($q) {
            $q->select(DB::raw('1'))->from('refunds as r')->whereColumn('r.order_id', 'o.id')->where('r.status', '!=', RefundStatus::Failed->value);
        })->count();

        return Metric::rate('refund_rate', 'Refund rate', $definition, $refunded, $orders);
    }

    public function gmv(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Gross merchandise value: the total of counted orders placed in the period, in '.Shop::currency().'. Counted orders are paid (or delivered without a required payment); cancelled lines are already out of an order total.';
        [$cents, $count, $note] = $this->orderValue($from, $to);

        return Metric::money('gmv', 'GMV', $definition, $count > 0 ? $cents : null, $note ?? ($count > 0 ? null : 'No counted orders in the period.'));
    }

    public function averageOrderValue(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'GMV divided by the number of counted orders, in '.Shop::currency().'.';
        [$cents, $count, $note] = $this->orderValue($from, $to);

        return Metric::money('aov', 'Average order value', $definition, $count > 0 ? intdiv($cents, $count) : null, $note ?? ($count > 0 ? null : 'No counted orders in the period.'));
    }

    public function paymentFailureRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Payment attempts that failed, divided by attempts that ended (succeeded or failed). Attempts still open or abandoned are not counted.';

        $base = fn () => DB::table('payments')->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $failed = (int) $base()->where('status', PaymentAttemptStatus::Failed->value)->count();
        $ended = (int) $base()->whereIn('status', [PaymentAttemptStatus::Failed->value, PaymentAttemptStatus::Success->value])->count();

        return Metric::rate('payment_failure_rate', 'Payment failure rate', $definition, $failed, $ended);
    }

    public function cancellationRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Orders placed in the period that are now cancelled (by buyer, seller, or expiry unpaid), divided by orders placed.';
        $placed = (int) DB::table('orders')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $cancelled = (int) DB::table('orders')->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('status', OrderStatus::Cancelled->value)->count();

        return Metric::rate('cancellation_rate', 'Cancellation rate', $definition, $cancelled, $placed);
    }

    public function sellerActivity(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Active stores with at least one counted order line in the period, divided by active stores.';
        $active = (int) DB::table('stores')->where('is_active', true)->count();

        $selling = (int) $this->counted->lines($from)->where('o.created_at', '<', $to)->whereNotNull('oi.store_id')
            ->whereIn('oi.store_id', DB::table('stores')->where('is_active', true)->select('id'))->distinct()->count('oi.store_id');

        return Metric::rate('seller_activity', 'Seller activity', $definition, $selling, $active);
    }

    /**
     * Event counts by store for the analytics publisher: aggregate numbers only, no event rows.
     *
     * @param  array<int, int>  $storeIds
     * @return array{product_views: int, add_to_cart: int, wishlist_adds: int}
     */
    public function storeEngagement(array $storeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($storeIds === []) {
            return ['product_views' => 0, 'add_to_cart' => 0, 'wishlist_adds' => 0];
        }

        $counts = DB::table('commerce_events')->whereIn('store_id', $storeIds)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('name', [Track::PRODUCT_VIEW, Track::ADD_TO_CART, Track::WISHLIST_ADD])
            ->groupBy('name')->selectRaw('name, count(*) as n')->pluck('n', 'name');

        return [
            'product_views' => (int) ($counts[Track::PRODUCT_VIEW] ?? 0),
            'add_to_cart' => (int) ($counts[Track::ADD_TO_CART] ?? 0),
            'wishlist_adds' => (int) ($counts[Track::WISHLIST_ADD] ?? 0),
        ];
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function events(string $name, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('commerce_events')->where('name', $name)->where('created_at', '>=', $from)->where('created_at', '<', $to);
    }

    private function personDays(string $name, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) DB::query()->fromSub($this->events($name, $from, $to)->select('actor_hash', 'day')->distinct(), 'd')->count();
    }

    /**
     * @return array{0: int, 1: int, 2: string|null} total cents, order count, a note
     */
    private function orderValue(CarbonInterface $from, CarbonInterface $to): array
    {
        $currency = Shop::currency();
        $rows = $this->counted->orders($from, $to)->groupBy('o.currency')->selectRaw('o.currency as currency, count(*) as n, sum(o.total) as total')->get();

        if ($rows->isEmpty()) {
            return [0, 0, null];
        }

        $mine = $rows->firstWhere('currency', $currency);
        $others = $rows->where('currency', '!=', $currency);
        $note = $others->isNotEmpty() ? 'Orders in other currencies ('.$others->pluck('currency')->implode(', ').') are not included: nothing is converted.' : null;

        return [$mine ? Money::toCents($mine->total) : 0, $mine ? (int) $mine->n : 0, $note];
    }
}
