<?php

namespace Tests\Feature\Discovery;

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Models\Product;
use App\Services\Analytics\CompanyDirectory;
use App\Services\Analytics\MetricsCollector;
use App\Services\Discovery\Track;
use App\Services\Metrics\CommerceMetrics;
use App\Services\Metrics\Metric;
use App\Services\Returns\ReturnService;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

class CommerceMetricsTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPaidOrders;
    use RefreshDatabase;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();
        // The parallel-process audit tests commit real rows (and so real events) on PostgreSQL and clean only their own.
        DB::table('commerce_events')->delete();
        $this->from = now()->subDays(7);
        $this->to = now()->addDay();
    }

    private function metric(string $key): Metric
    {
        return collect(app(CommerceMetrics::class)->all($this->from, $this->to))->firstWhere('key', $key);
    }

    private function product(): Product
    {
        return $this->makeListing($this->makeSeller(), ['stock' => 100]);
    }

    public function test_with_no_rows_every_metric_says_no_data_and_never_zero(): void
    {
        $all = app(CommerceMetrics::class)->all($this->from, $this->to);

        $this->assertCount(13, $all);

        foreach ($all as $metric) {
            $this->assertSame(Metric::NO_DATA, $metric->status, $metric->key);
            $this->assertNull($metric->value, $metric->key);
            $this->assertSame('No data yet', $metric->toArray()['display']);
            $this->assertNotSame('', $metric->definition, $metric->key.' has no definition');
        }
    }

    public function test_the_funnel_metrics(): void
    {
        $product = $this->product();
        $track = app(Track::class);
        $a = Actor::forToken('visitor-a-'.str_repeat('a', 20));
        $b = Actor::forToken('visitor-b-'.str_repeat('b', 20));
        $c = Actor::forToken('visitor-c-'.str_repeat('c', 20));

        // a searches, views from the results, adds to cart, starts checkout and pays.
        $track->search($a, 3);
        $track->productView($a, $product, 'search');
        $track->addToCart($a, $product);
        $track->checkoutStarted($a);
        $track->record(Track::ORDER_PAID, $a, ['order_id' => 1]);
        // b searches and leaves; views nothing.
        $track->search($b, 0);
        // c views a product directly, adds to cart and abandons.
        $track->productView($c, $product, 'direct');
        $track->addToCart($c, $product);

        $this->assertSame([1, 2], [$this->metric('discovery_success')->numerator, $this->metric('discovery_success')->denominator]);
        $this->assertSame(0.5, $this->metric('discovery_success')->value);
        $this->assertSame([1, 2], [$this->metric('search_to_product')->numerator, $this->metric('search_to_product')->denominator]);
        $this->assertSame([2, 2], [$this->metric('add_to_cart_rate')->numerator, $this->metric('add_to_cart_rate')->denominator]);
        $this->assertSame([1, 1], [$this->metric('checkout_completion')->numerator, $this->metric('checkout_completion')->denominator]);
        $this->assertSame([1, 2], [$this->metric('cart_abandonment')->numerator, $this->metric('cart_abandonment')->denominator]);
    }

    public function test_a_view_before_the_search_does_not_count_as_discovery_success(): void
    {
        $product = $this->product();
        $a = Actor::forToken('visitor-a-'.str_repeat('a', 20));
        $track = app(Track::class);

        $track->productView($a, $product);
        $this->travel(5)->minutes();
        $track->search($a, 4);

        $this->assertSame([0, 1], [$this->metric('discovery_success')->numerator, $this->metric('discovery_success')->denominator]);
    }

    public function test_gmv_aov_and_repeat_purchase_come_from_counted_orders_only(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 100.00, 'stock' => 100]);
        $buyer = $this->makeBuyer();
        $other = $this->makeBuyer();

        $this->paidOrder($buyer, [$product]);
        $this->paidOrder($buyer, [$product]);
        $this->paidOrder($other, [$product]);
        // Placed but never paid: not counted.
        $unpaid = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);
        $unpaid->forceFill(['requires_payment' => true, 'payment_status' => 'pending'])->save();

        $this->assertSame(30000, $this->metric('gmv')->value);
        $this->assertSame(10000, $this->metric('aov')->value);
        $this->assertSame([1, 2], [$this->metric('repeat_purchase_rate')->numerator, $this->metric('repeat_purchase_rate')->denominator]);
        $this->assertSame([0, 4], [$this->metric('cancellation_rate')->numerator, $this->metric('cancellation_rate')->denominator]);
    }

    public function test_cancellation_payment_failure_and_refund_rates(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 50.00, 'stock' => 100]);
        $paid = $this->paidOrder($this->makeBuyer(), [$product]);
        $cancelled = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);
        $cancelled->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->assertSame([1, 2], [$this->metric('cancellation_rate')->numerator, $this->metric('cancellation_rate')->denominator]);

        $now = now();
        foreach ([['success', 1], ['failed', 2], ['failed', 3], ['abandoned', 4], ['pending', 5]] as [$status, $n]) {
            DB::table('payments')->insert(['order_id' => $paid->id, 'reference' => 'REF-'.$n, 'amount_cents' => 5000, 'currency' => 'ZAR', 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->assertSame([2, 3], [$this->metric('payment_failure_rate')->numerator, $this->metric('payment_failure_rate')->denominator], 'abandoned and pending attempts are not counted');

        $this->assertSame([0, 1], [$this->metric('refund_rate')->numerator, $this->metric('refund_rate')->denominator]);
        $paymentId = DB::table('payments')->where('reference', 'REF-1')->value('id');
        DB::table('refunds')->insert(['payment_id' => $paymentId, 'order_id' => $paid->id, 'amount_cents' => 5000, 'currency' => 'ZAR', 'status' => 'refunded', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame([1, 1], [$this->metric('refund_rate')->numerator, $this->metric('refund_rate')->denominator]);
    }

    public function test_return_rate_says_no_data_yet_until_something_has_been_delivered(): void
    {
        $metric = $this->metric('return_rate');

        $this->assertSame(Metric::NO_DATA, $metric->status);
        $this->assertNull($metric->value);
        $this->assertSame('No data yet', $metric->toArray()['display']);
        $this->assertStringContainsString('nothing to compare returns with', (string) $metric->note);
    }

    public function test_return_rate_is_requests_over_lines_delivered_in_the_period(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 80.00, 'stock' => 100]);
        $buyer = $this->makeBuyer();
        $first = $this->paidOrder($buyer, [$product], delivered: true);
        $this->paidOrder($this->makeBuyer(), [$product], delivered: true);

        $zero = $this->metric('return_rate');
        $this->assertSame([0, 2], [$zero->numerator, $zero->denominator], 'two lines delivered and none returned is a real zero, not a missing figure');
        $this->assertSame(Metric::OK, $zero->status);

        app(ReturnService::class)->request($first->items->first()->refresh(), $buyer, 'faulty', 'It does not switch on');

        $this->assertSame([1, 2], [$this->metric('return_rate')->numerator, $this->metric('return_rate')->denominator]);
        $this->assertSame(0.5, $this->metric('return_rate')->value);
    }

    public function test_seller_activity(): void
    {
        $active = $this->makeSeller();
        $this->makeSeller();
        $product = $this->makeListing($active, ['stock' => 10]);
        $this->paidOrder($this->makeBuyer(), [$product]);

        $this->assertSame([1, 2], [$this->metric('seller_activity')->numerator, $this->metric('seller_activity')->denominator]);
    }

    public function test_order_paid_is_recorded_once_per_order_and_without_personal_data(): void
    {
        $product = $this->product();
        $buyer = $this->makeBuyer();
        $order = $this->paidOrder($buyer, [$product]);

        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order);

        $this->assertSame(1, DB::table('commerce_events')->where('name', 'order_paid')->count());
        $row = DB::table('commerce_events')->where('name', 'order_paid')->first();
        $this->assertSame($order->totalCents(), (int) $row->value_cents);
        $this->assertSame(Actor::hashForUser($buyer->id), $row->actor_hash);
        $this->assertSame(
            ['id', 'name', 'actor_hash', 'day', 'product_id', 'store_id', 'order_id', 'value_cents', 'source', 'properties', 'created_at'],
            array_keys((array) $row),
        );
        $this->assertStringNotContainsString($buyer->email, json_encode($row));
    }

    public function test_anonymous_requests_without_a_session_record_nothing(): void
    {
        app(Track::class)->productView(Actor::none(), $this->product());

        $this->assertSame(0, DB::table('commerce_events')->count());
    }

    public function test_old_events_are_pruned(): void
    {
        app(Track::class)->search($this->visitorActor(), 1);
        DB::table('commerce_events')->update(['created_at' => now()->subDays(401)]);

        $this->artisan('emall:discovery-prune')->assertSuccessful();

        $this->assertSame(0, DB::table('commerce_events')->count());
    }

    private function visitorActor(): Actor
    {
        return Actor::forToken('visitor-a-'.str_repeat('a', 20));
    }

    // --- the Dot.Analytics publisher -----------------------------------------------------------------------------

    public function test_the_publisher_adds_aggregate_engagement_only_when_switched_on(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $track = app(Track::class);
        $track->productView($this->visitorActor(), $product);
        $track->productView(Actor::forToken('visitor-b-'.str_repeat('b', 20)), $product);
        $track->addToCart($this->visitorActor(), $product);
        $takenAt = Carbon::now('UTC')->addDay()->startOfDay();

        $collector = new MetricsCollector($this->createStub(CompanyDirectory::class));

        $off = $collector->collect([$seller->id], $takenAt);
        $this->assertSame([], array_intersect_key($off, array_flip(MetricsCollector::COMMERCE_KEYS)));

        config(['analytics.commerce_metrics' => true]);
        $on = $collector->collect([$seller->id], $takenAt);

        $this->assertSame(2, $on['product_views_7d']);
        $this->assertSame(1, $on['add_to_cart_7d']);
        $this->assertSame(0, $on['wishlist_adds_7d']);
        $this->assertSame([], array_diff(array_keys($on), array_merge(MetricsCollector::KEYS, MetricsCollector::COMMERCE_KEYS)), 'only counts, nothing row-level');
    }
}
