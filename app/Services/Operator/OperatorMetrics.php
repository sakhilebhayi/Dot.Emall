<?php

namespace App\Services\Operator;

use App\Enums\PaymentStatus;
use App\Models\Product;
use App\Services\Discovery\CountedOrders;
use App\Services\Metrics\CommerceMetrics;
use App\Services\Metrics\Metric;
use App\Services\Shipping\BusinessDays;
use App\Support\Money;
use App\Support\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The operator's metrics page: the thirteen CommerceMetrics plus the four the operator also needs (active products, active sellers,
 * seller fulfilment, dispute rate), over a chosen period, with a daily series for the two honest charts. All from real rows; a number
 * with nothing behind it is "No data yet", never zero. Aggregates are cached for 60 seconds per period (the page costs a fixed number of
 * queries however busy the shop is).
 *
 * Dot.Analytics publisher keys (App\Services\Analytics\MetricsCollector): this page does NOT publish anything. The snapshot sent to Dot.Analytics
 * per company holds orders_7d, revenue_7d_cents (with currency), low_stock_products and, only with ANALYTICS_COMMERCE_METRICS=true, product_views_7d,
 * add_to_cart_7d and wishlist_adds_7d (aggregate counts, D-39). The definitions below are the operator's own marketplace-wide figures and are not those keys.
 */
class OperatorMetrics
{
    public const PERIODS = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'month' => 'This month'];

    public function __construct(private readonly CommerceMetrics $commerce, private readonly CountedOrders $counted) {}

    /** @return array{from: Carbon, to: Carbon} [from, to) */
    public function window(string $period): array
    {
        $to = now()->addDay()->startOfDay();

        return match ($period) {
            '7d' => ['from' => $to->copy()->subDays(7), 'to' => $to],
            '90d' => ['from' => $to->copy()->subDays(90), 'to' => $to],
            'month' => ['from' => now()->startOfMonth(), 'to' => $to],
            default => ['from' => $to->copy()->subDays(30), 'to' => $to],
        };
    }

    public function normalise(?string $period): string
    {
        return array_key_exists((string) $period, self::PERIODS) ? (string) $period : '30d';
    }

    /**
     * @return array{period: string, from: Carbon, to: Carbon, metrics: list<Metric>, series: list<array{day: string, orders: int, gmv_cents: int}>}
     */
    public function report(?string $period = null): array
    {
        $period = $this->normalise($period);
        $w = $this->window($period);

        // Plain arrays in the cache, never objects: the cache refuses to rebuild classes (config cache.serializable_classes is false),
        // and a cached Metric would come back as an incomplete object.
        $state = Cache::remember('operator.metrics.v2.'.$period.'.'.$w['to']->format('Ymd'), 60, function () use ($w) {
            return [
                'metrics' => array_map(fn (Metric $m) => $this->state($m), [...$this->commerce->all($w['from'], $w['to']), ...$this->extra($w['from'], $w['to'])]),
                'series' => $this->series($w['from'], $w['to']),
            ];
        });

        return [
            'period' => $period,
            'from' => $w['from'],
            'to' => $w['to'],
            'metrics' => array_map(fn (array $row) => new Metric(...$row), $state['metrics']),
            'series' => $state['series'],
        ];
    }

    /** @return array<string, mixed> the Metric's constructor arguments, as plain data */
    private function state(Metric $m): array
    {
        return ['key' => $m->key, 'label' => $m->label, 'definition' => $m->definition, 'unit' => $m->unit, 'value' => $m->value, 'status' => $m->status, 'numerator' => $m->numerator, 'denominator' => $m->denominator, 'note' => $m->note];
    }

    /** @return list<Metric> */
    public function extra(CarbonInterface $from, CarbonInterface $to): array
    {
        return [$this->activeProducts(), $this->activeSellers(), $this->sellerFulfilment($from, $to), $this->disputeRate($from, $to)];
    }

    public function activeProducts(): Metric
    {
        $definition = 'Listings a shopper can buy right now: active, approved, in an open store, not archived. A snapshot of today, not of the period.';
        $n = Product::query()->browsable()->count();

        return Metric::count('active_products', 'Active products', $definition, $n, $n > 0, 'No product is listed yet.');
    }

    public function activeSellers(): Metric
    {
        $definition = 'Open stores with at least one listing a shopper can buy right now. A snapshot of today, not of the period.';
        $n = (int) DB::table('stores')->where('is_active', true)->whereExists(function ($q) {
            $q->select(DB::raw('1'))->from('products as p')->whereColumn('p.store_id', 'stores.id')->where('p.is_active', true)->where('p.status', 'approved')->whereNull('p.deleted_at');
        })->count();

        return Metric::count('active_sellers', 'Active sellers', $definition, $n, $n > 0, 'No open store has a listing yet.');
    }

    public function sellerFulfilment(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Of paid parcels placed in the period whose handling time has passed or that have shipped, the share shipped within the handling business days the seller set, counted from payment. The same rule as the store page uses.';

        $groups = DB::table('order_groups as g')->join('orders as o', 'o.id', '=', 'g.order_id')
            ->whereIn('o.payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->where('o.created_at', '>=', $from)->where('o.created_at', '<', $to)->where('g.status', '!=', 'cancelled')
            ->limit(20000)->get(['o.paid_at', 'o.created_at as placed_at', 'g.shipped_at', 'g.handling_days']);

        $due = 0;
        $onTime = 0;

        foreach ($groups as $g) {
            $start = Carbon::parse($g->paid_at ?? $g->placed_at);
            $deadline = BusinessDays::add($start, (int) $g->handling_days)->endOfDay();

            if ($g->shipped_at === null && $deadline->isFuture()) {
                continue;
            }

            $due++;

            if ($g->shipped_at !== null && Carbon::parse($g->shipped_at)->lte($deadline)) {
                $onTime++;
            }
        }

        return Metric::rate('seller_fulfilment', 'Seller fulfilment', $definition, $onTime, $due, 'A parcel still inside its handling time is not counted either way. Collection parcels are included.');
    }

    public function disputeRate(CarbonInterface $from, CarbonInterface $to): Metric
    {
        $definition = 'Return requests opened in the period that reached the operator (escalated), divided by return requests opened in the period.';
        $base = fn () => DB::table('return_requests')->where('created_at', '>=', $from)->where('created_at', '<', $to);

        return Metric::rate('dispute_rate', 'Dispute rate', $definition, (int) $base()->whereNotNull('escalated_at')->count(), (int) $base()->count());
    }

    /**
     * Counted orders and their value per day, with the days that had none filled in as zero (a quiet day IS zero orders; the chart
     * needs the day to exist so the baseline is honest). The page decides whether the whole period has data.
     *
     * @return list<array{day: string, orders: int, gmv_cents: int}>
     */
    public function series(CarbonInterface $from, CarbonInterface $to): array
    {
        $currency = Shop::currency();
        $rows = $this->counted->orders($from, $to)->where('o.currency', $currency)
            ->selectRaw('date(o.created_at) as day, count(*) as n, sum(o.total) as total')->groupBy(DB::raw('date(o.created_at)'))->get()->keyBy('day');

        $out = [];

        for ($d = Carbon::parse($from)->startOfDay(); $d->lt($to); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $row = $rows[$key] ?? null;
            $out[] = ['day' => $key, 'orders' => $row ? (int) $row->n : 0, 'gmv_cents' => $row ? Money::toCents($row->total) : 0];
        }

        return $out;
    }

    /** The value as a person reads it: "12.5%", "R1,200.00", "142", or "No data yet". */
    public static function display(Metric $m): string
    {
        if (! $m->hasData()) {
            return 'No data yet';
        }

        return match ($m->unit) {
            'rate' => rtrim(rtrim(number_format($m->value * 100, 1), '0'), '.').'%',
            'cents' => Shop::money((int) $m->value),
            default => number_format((float) $m->value),
        };
    }

    /**
     * @return list<list<string|int|float|null>> header row then one row per metric, for the CSV export
     */
    public function csvRows(?string $period = null): array
    {
        $report = $this->report($period);
        $rows = [['metric', 'key', 'value', 'unit', 'numerator', 'denominator', 'status', 'period_from', 'period_to', 'definition']];

        foreach ($report['metrics'] as $m) {
            $rows[] = [
                $m->label,
                $m->key,
                $m->hasData() ? ($m->unit === 'cents' ? Money::fromCents((int) $m->value) : $m->value) : '',
                $m->unit === 'cents' ? Shop::currency() : $m->unit,
                $m->numerator ?? '',
                $m->denominator ?? '',
                $m->hasData() ? 'ok' : 'no data yet',
                $report['from']->format('Y-m-d'),
                $report['to']->copy()->subDay()->format('Y-m-d'),
                $m->definition,
            ];
        }

        return $rows;
    }
}
