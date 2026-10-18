<?php

namespace Tests\Feature\Analytics;

use App\Enums\OrderStatus;
use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * emall:publish-metrics end to end: one queued, signed job per company, the same snapshot
 * identity on every run of a day, and no row-level data anywhere in what leaves the app.
 */
class PublishMetricsCommandTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-emall-tests-0001';

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const CONTRACT = ['orders_7d', 'revenue_7d_cents', 'low_stock_products', 'currency'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        Cache::flush();
        config([
            'analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'emall', 'timeout' => 5, 'currency' => null,
                'circuit' => ['threshold' => 5, 'pause_seconds' => 60]],
        ]);
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['data' => ['replayed' => false]], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(?string $uuid, array $store = []): User
    {
        $seller = $this->makeSeller($store);

        if ($uuid !== null) {
            $seller->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $seller->fresh();
    }

    /** @return list<array{request: Request, json: array<string, mixed>}> */
    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            $out[] = ['request' => $request, 'json' => json_decode($request->body(), true)];
        }

        return $out;
    }

    /** @return array{0: User, 1: User} */
    private function twoCompanies(): array
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $this->makeListing($a, ['stock' => 1]);
        $this->makeListing($b, ['stock' => 1]);
        $this->makeListing($b, ['stock' => 2]);

        return [$a, $b];
    }

    // ---- one job per company ---------------------------------------------------------------

    public function test_one_job_is_queued_per_company_with_the_final_payload(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->makeListing($this->company(null), ['stock' => 1]);   // an unlinked seller is nobody's company
        $this->makeBuyer();                                          // and a buyer who owns no store is not a company at all

        $this->artisan('emall:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 2 of 2 snapshot(s) for 2026-11-05T00:00:00Z.');

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 2);
        $payloads = [];
        Bus::assertDispatched(SendAnalyticsSnapshot::class, function (SendAnalyticsSnapshot $job) use (&$payloads) {
            $payloads[$job->payload['company_uuid']] = $job->payload;

            return true;
        });
        ksort($payloads);
        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($payloads));
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($payloads[self::COMPANY_A]));
        $this->assertSame(1, $payloads[self::COMPANY_A]['metrics']['low_stock_products']);
        $this->assertSame(2, $payloads[self::COMPANY_B]['metrics']['low_stock_products']);
        Http::assertNothingSent();
    }

    public function test_with_the_queue_running_inline_each_company_gets_one_signed_request_with_only_contract_keys(): void
    {
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $byCompany = [];
        foreach ($sent as ['request' => $request, 'json' => $json]) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET), $request->header('X-InfoDot-Signature')[0]);
            $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
            $this->assertSame('emall', $json['platform']);
            $this->assertSame('ZAR', $json['metrics']['currency']);
            $this->assertSame('2026-11-05T00:00:00Z', $json['taken_at']);
            $this->assertSame('day', $json['period']);
            $this->assertSame([], array_diff(array_keys($json['metrics']), self::CONTRACT), 'only contract keys');
            $byCompany[$json['company_uuid']] = $json['metrics'];
        }
        $this->assertSame(1, $byCompany[self::COMPANY_A]['low_stock_products']);
        $this->assertSame(2, $byCompany[self::COMPANY_B]['low_stock_products']);
    }

    // ---- replay safety ---------------------------------------------------------------------

    public function test_running_twice_on_the_same_day_sends_the_same_snapshot_identity_even_hours_apart(): void
    {
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-05 17:42:09');
        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(4, $sent);
        $identities = array_map(fn ($e) => $e['json']['platform'].'|'.$e['json']['company_uuid'].'|'.$e['json']['taken_at'].'|'.$e['json']['period'], $sent);
        $this->assertCount(2, array_unique($identities), 'two companies, one identity each, however often it runs that day');
        foreach ($sent as $e) {
            $this->assertSame('2026-11-05T00:00:00Z', $e['json']['taken_at']);
        }
    }

    public function test_the_next_utc_day_is_a_new_snapshot_and_a_late_night_run_belongs_to_its_own_utc_day(): void
    {
        $this->twoCompanies();

        Carbon::setTestNow('2026-11-05 23:59:59');
        $this->artisan('emall:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $days = array_unique(array_map(fn ($e) => $e['json']['taken_at'], $this->sent()));
        sort($days);
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $days);
    }

    public function test_a_late_retry_resends_the_payload_it_was_queued_with_not_fresh_numbers(): void
    {
        Bus::fake();
        [$a] = $this->twoCompanies();
        $this->artisan('emall:publish-metrics')->assertSuccessful();
        $job = Bus::dispatched(SendAnalyticsSnapshot::class)->first(fn ($j) => $j->payload['company_uuid'] === self::COMPANY_A);

        $this->makeListing($a, ['stock' => 0]);       // the world moves on...
        $this->makeListing($a, ['stock' => 0]);
        Carbon::setTestNow('2026-11-05 09:00:00');    // ...and the retry happens hours later
        $job->handle(app(AnalyticsClient::class));

        $body = $this->sent()[0]['json'];
        $this->assertSame(1, $body['metrics']['low_stock_products']);
        $this->assertSame('2026-11-05T00:00:00Z', $body['taken_at']);
    }

    // ---- options ---------------------------------------------------------------------------

    public function test_company_limits_the_run_to_one_company(): void
    {
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics', ['--company' => strtoupper(self::COMPANY_B)])->assertSuccessful();

        $this->assertSame([self::COMPANY_B], array_map(fn ($e) => $e['json']['company_uuid'], $this->sent()));
    }

    public function test_company_with_nothing_to_publish_sends_nothing_and_a_malformed_uuid_is_an_error(): void
    {
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics', ['--company' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])
            ->assertSuccessful()->expectsOutputToContain('No company has anything to publish for that uuid.');
        $this->artisan('emall:publish-metrics', ['--company' => 'not-a-uuid'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_prints_the_numbers_and_queues_and_sends_nothing(): void
    {
        Bus::fake();
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain(self::COMPANY_A.' {"orders_7d":0,"revenue_7d_cents":0,"low_stock_products":1,"currency":"ZAR"}')
            ->expectsOutputToContain('Dry run: 2 snapshot(s) for 2026-11-05T00:00:00Z would be sent. Nothing was queued.');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_dry_run_works_before_the_secret_is_set_and_still_sends_nothing(): void
    {
        config(['analytics.secret' => null, 'analytics.url' => null]);
        $this->twoCompanies();

        $this->artisan('emall:publish-metrics', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain(self::COMPANY_B);

        Http::assertNothingSent();
    }

    // ---- nothing until configured -----------------------------------------------------------

    public function test_nothing_is_collected_queued_or_sent_while_the_url_or_the_secret_is_blank(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->mock(MetricsCollector::class)->shouldNotReceive('snapshots');

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['analytics' => array_merge(config('analytics'), ['url' => self::URL, 'secret' => self::SECRET], $blank)]);

            $this->artisan('emall:publish-metrics')->assertSuccessful()->expectsOutputToContain('publishing is off');
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    // ---- Analytics can never hurt the app ----------------------------------------------------

    public function test_an_analytics_outage_leaves_the_command_the_data_and_the_app_untouched(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('boom', 503)]);
        [$a] = $this->twoCompanies();
        $orders = Order::query()->count();
        $products = Product::query()->count();

        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $this->assertSame($orders, Order::query()->count());
        $this->assertSame($products, Product::query()->count());
        $this->assertSame(1, Product::query()->where('store_id', $a->store->id)->count());
        $this->assertGreaterThanOrEqual(2, count($this->sent()));
    }

    public function test_a_connection_failure_or_a_dead_queue_does_not_fail_the_command(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->twoCompanies();
        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down'));
        });
        $this->artisan('emall:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 0 of 2');
    }

    public function test_a_collector_bug_is_logged_and_fails_only_this_command_with_nothing_sent(): void
    {
        $this->mock(MetricsCollector::class)->shouldReceive('snapshots')->andThrow(new \RuntimeException('bad query'));

        $this->artisan('emall:publish-metrics')->assertFailed()->expectsOutputToContain('Nothing was sent.');

        Http::assertNothingSent();
    }

    // ---- aggregates only ----------------------------------------------------------------------

    public function test_no_row_level_data_is_in_the_raw_body(): void
    {
        $long = fn (string $label, string $fill) => 'SENTINEL-'.$label.'-'.str_repeat($fill, 60);
        $sentinels = [
            'seller_name' => $long('SELLER-NAME', 's'),
            'seller_email' => 'sentinel-seller-email-'.str_repeat('e', 40).'@example.test',
            'store_name' => $long('STORE-NAME', 't'),
            'store_description' => $long('STORE-DESCRIPTION', 'x'),
            'product_name' => $long('PRODUCT-NAME', 'p'),
            'product_description' => $long('PRODUCT-DESCRIPTION', 'q'),
            'sku' => $long('SKU', 'k'),
            'buyer_name' => $long('BUYER-NAME', 'b'),
            'buyer_email' => 'sentinel-buyer-email-'.str_repeat('m', 40).'@example.test',
            'shipping_name' => $long('SHIPPING-NAME', 'n'),
            'shipping_address' => $long('SHIPPING-ADDRESS', 'a'),
            'shipping_phone' => '+27-SENTINEL-'.str_repeat('9', 30),
            'promo' => 'SENTINELPROMO'.str_repeat('Z', 30),
        ];
        $seller = $this->makeSeller(['name' => $sentinels['store_name'], 'description' => $sentinels['store_description']]);
        $seller->forceFill(['name' => $sentinels['seller_name'], 'email' => $sentinels['seller_email']])->save();
        $seller->currentTeam->forceFill(['infodot_team_uuid' => self::COMPANY_A, 'name' => $long('TEAM-NAME', 'y')])->save();
        $product = $this->makeListing($seller, ['name' => $sentinels['product_name'], 'description' => $sentinels['product_description'], 'sku' => $sentinels['sku'], 'price' => '777.77', 'stock' => 2]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => $sentinels['promo'], 'discount_value' => 10]);
        $buyer = User::factory()->withPersonalTeam()->create(['name' => $sentinels['buyer_name'], 'email' => $sentinels['buyer_email']]);
        $order = $this->placeOrder($buyer, [[$product, 1]], [
            'shipping_name' => $sentinels['shipping_name'], 'shipping_address' => $sentinels['shipping_address'],
            'shipping_phone' => $sentinels['shipping_phone'], 'promo_code' => $sentinels['promo'],
        ]);
        $order->forceFill(['created_at' => Carbon::parse('2026-11-02 10:00:00')])->save();
        $order->items[0]->update(['status' => OrderStatus::Delivered]);

        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $body = $sent[0]['request']->body();
        $json = $sent[0]['json'];
        $this->assertSame(['orders_7d' => 1, 'revenue_7d_cents' => 69999, 'low_stock_products' => 1, 'currency' => 'ZAR'], $json['metrics'], '77777 cents less a 10% promo of 7778 (7777.7 rounded)');
        foreach ($sentinels as $label => $sentinel) {
            $this->assertStringNotContainsString($sentinel, $body, "{$label} leaked");
        }
        foreach (['SENTINEL', 'example.test', '777.77', 'address', 'email', 'name', 'sku', 'promo', 'phone', 'description'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$needle} leaked");
        }
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
        $this->assertSame([], array_diff(array_keys($json['metrics']), self::CONTRACT));
        foreach ($json['metrics'] as $key => $value) {
            $this->assertTrue(is_int($value) || $key === 'currency', "{$key} must be a whole number");
        }
    }

    public function test_without_an_analytics_override_the_storefront_currency_is_published(): void
    {
        config(['analytics.currency' => null, 'shop.currency' => 'ZAR']);
        $seller = $this->company(self::COMPANY_A);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller, ['price' => 100]), 1]]);
        $order->forceFill(['created_at' => Carbon::parse('2026-11-02 10:00:00')])->save();
        $order->items[0]->update(['status' => OrderStatus::Delivered]);

        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $this->assertSame(['orders_7d' => 1, 'revenue_7d_cents' => 10000, 'low_stock_products' => 0, 'currency' => 'ZAR'], $this->sent()[0]['json']['metrics']);
    }

    public function test_a_company_with_several_currencies_in_the_window_sends_no_money_key(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $listing = $this->makeListing($seller, ['price' => 100, 'stock' => 50]);
        foreach (['ZAR' => '2026-11-02 10:00:00', 'EUR' => '2026-11-03 10:00:00'] as $currency => $when) {
            config(['shop.currency' => $currency]);
            $order = $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);
            $order->forceFill(['created_at' => Carbon::parse($when)])->save();
            $order->items[0]->update(['status' => OrderStatus::Delivered]);
        }

        $this->artisan('emall:publish-metrics')->assertSuccessful();

        $this->assertSame(['orders_7d', 'low_stock_products'], array_keys($this->sent()[0]['json']['metrics']));
        $this->assertStringNotContainsString('revenue', $this->sent()[0]['request']->body());
    }

    // ---- schedule ----------------------------------------------------------------------------

    public function test_it_is_scheduled_once_a_day_just_after_midnight_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'emall:publish-metrics'));

        $this->assertNotNull($event, 'emall:publish-metrics is on the schedule');
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_stays_on_utc_even_when_the_app_timezone_is_not(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $this->app->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'emall:publish-metrics'));

        Carbon::setTestNow(Carbon::parse('2026-11-05 00:15:00', 'UTC'));
        $dueAtUtcMidnight = $event->isDue($this->app);
        Carbon::setTestNow(Carbon::parse('2026-11-04 22:15:00', 'UTC')); // 00:15 in Johannesburg
        $dueAtLocalMidnight = $event->isDue($this->app);

        $this->assertTrue($dueAtUtcMidnight);
        $this->assertFalse($dueAtLocalMidnight);
    }
}
