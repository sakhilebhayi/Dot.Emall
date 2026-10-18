<?php

namespace App\Services\Analytics;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\SellerDashboard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers Dot.Emall publishes to Dot.Analytics: pure read-only aggregate queries, scoped
 * explicitly to the sellers of ONE company. Only the keys of Dot.Analytics' metrics contract for
 * the `emall` platform are ever produced: orders_7d, revenue_7d_cents (with `currency`) and
 * low_stock_products. No name, product, buyer, address or row ever leaves here.
 *
 * Who a company is: stores belong to their OWNER (a user), and Dot.Analytics wants a company, so a
 * company is the InfoDot team (infodot_team_uuid) the store owner currently belongs to (see
 * CompanyDirectory). The sellers of one company are added together. A seller with no linked team
 * is in no company and is never published.
 *
 * The definitions are the seller dashboard's own (App\Services\SellerDashboard), so the two
 * can never disagree about what a number means. The unit is the seller's own order LINE, matched
 * on the seller_id snapshot taken at checkout, so archiving or deleting a listing never moves
 * history between sellers or out of the numbers:
 *
 *   orders_7d           distinct orders holding at least one non-cancelled line of the company's
 *                       sellers, for orders PLACED (orders.created_at) in the window
 *   revenue_7d_cents    sum of OrderItem::netCents() (line total less its promo share) over the
 *                       company's DELIVERED lines of orders placed in the window. No delivery
 *                       timestamp exists, so a line counts in the window its order was placed in;
 *                       "revenue" is the value of delivered lines, not money received (payment is
 *                       not implemented). Left out, with `currency`, unless ANALYTICS_CURRENCY is
 *                       declared: Dot.Emall has no currency setting and none is assumed
 *   low_stock_products  active products in the company's stores with fewer than
 *                       SellerDashboard::LOW_STOCK_BELOW in stock, right now. Archived products
 *                       are not counted
 *
 * Windows are UTC. taken_at is the start of a UTC day and the "7d" metrics cover the seven whole
 * UTC days before it, [taken_at - 7d, taken_at): the same day always yields the same 7 day
 * numbers however late it runs. Bounds are converted to the app timezone before they reach a
 * query because timestamps are stored in it.
 */
class MetricsCollector
{
    /** @var list<string> */
    public const KEYS = ['orders_7d', 'revenue_7d_cents', 'low_stock_products', 'currency'];

    public function __construct(private readonly CompanyDirectory $directory) {}

    /**
     * One snapshot per company that has a store (or sold something).
     *
     * @return array<string, array<string, int|string>> company uuid => metrics
     */
    public function snapshots(CarbonInterface $takenAt, ?string $onlyCompany = null): array
    {
        $sellerIds = Store::query()->pluck('owner_id')
            ->merge(OrderItem::query()->whereNotNull('seller_id')->distinct()->pluck('seller_id'))
            ->unique()
            ->values()
            ->all();
        $out = [];

        foreach ($this->directory->group($sellerIds) as $uuid => $members) {
            if ($onlyCompany !== null && strtolower($uuid) !== strtolower($onlyCompany)) {
                continue;
            }

            $metrics = $this->collect($members, $takenAt);

            if ($metrics !== []) {
                $out[$uuid] = $metrics;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $sellerIds  the users of ONE company (never empty meaning "everyone")
     * @return array<string, int|string>
     */
    public function collect(array $sellerIds, CarbonInterface $takenAt): array
    {
        if ($sellerIds === []) {
            return [];
        }

        $to = Carbon::instance($takenAt)->utc()->startOfDay()->timezone((string) config('app.timezone'));
        $from = $to->copy()->subDays(7);

        $lines = fn (): Builder => OrderItem::query()
            ->whereIn('seller_id', $sellerIds)
            ->whereIn('order_id', Order::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->select('id'));

        $metrics = [
            'orders_7d' => $lines()->where('status', '!=', OrderStatus::Cancelled->value)->distinct()->count('order_id'),
        ];

        $currency = strtoupper(trim((string) config('analytics.currency')));

        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $revenue = 0;

            foreach ($lines()->where('status', OrderStatus::Delivered->value)->select(['id', 'total', 'discount_amount'])->cursor() as $line) {
                $revenue += $line->netCents();
            }

            $metrics['revenue_7d_cents'] = $revenue;
        }

        $metrics['low_stock_products'] = Product::query()
            ->whereIn('store_id', Store::query()->whereIn('owner_id', $sellerIds)->select('id'))
            ->where('is_active', true)
            ->where('stock', '<', SellerDashboard::LOW_STOCK_BELOW)
            ->count();

        if (isset($metrics['revenue_7d_cents'])) {
            $metrics['currency'] = $currency;
        }

        return $metrics;
    }
}
