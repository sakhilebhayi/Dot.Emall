<?php

namespace Tests\Feature\Analytics;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\CompanyDirectory;
use App\Services\Analytics\MetricsCollector;
use App\Services\SellerDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * What Dot.Emall measures for each company (the team of the store owners), and above all what it
 * must never include: another company's lines, products or stores, cancelled lines, anything
 * outside the UTC window, and money in a currency nobody declared.
 *
 * "Now" is 2026-11-05 00:15 UTC, so taken_at is 2026-11-05T00:00Z and the 7 day window is
 * [2026-10-29 00:00Z, 2026-11-05 00:00Z).
 */
class MetricsCollectorTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** The only metric keys Dot.Analytics' config/metrics.php accepts for `emall` (PR #15), plus `currency`. */
    private const CONTRACT = ['orders_7d', 'revenue_7d_cents', 'low_stock_products', 'currency'];

    private Carbon $takenAt;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        $this->takenAt = Carbon::parse('2026-11-05 00:00:00', 'UTC');
        // The currency comes from the storefront (SHOP_CURRENCY, ZAR by default); ANALYTICS_CURRENCY is only an override.
        config(['analytics.currency' => null, 'shop.currency' => 'ZAR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(?string $uuid): User
    {
        $seller = $this->makeSeller();

        if ($uuid !== null) {
            $seller->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $seller->fresh();
    }

    /** Another seller whose current team is $of's current team: the same company. */
    private function colleague(User $of): User
    {
        $seller = $this->makeSeller();
        $seller->forceFill(['current_team_id' => $of->current_team_id])->save();

        return $seller->fresh();
    }

    /** A time given in UTC, as the wall-clock value the database holds in the app timezone. */
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC')->timezone(config('app.timezone'));
    }

    /**
     * An order placed through the real checkout, then dated and with its lines moved to the given statuses.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines
     * @param  array<int, OrderStatus>  $statuses  by line position; unlisted lines stay pending
     */
    private function order(array $lines, string $createdUtc, array $statuses = [], array $input = []): Order
    {
        $order = $this->placeOrder($this->makeBuyer(), $lines, $input);
        $order->forceFill(['created_at' => $this->at($createdUtc), 'updated_at' => $this->at($createdUtc)])->save();

        foreach ($statuses as $position => $status) {
            $order->items[$position]->update(['status' => $status]);
        }

        return $order->load('items');
    }

    /** @return array<string, int|string> */
    private function collect(User ...$sellers): array
    {
        return app(MetricsCollector::class)->collect(array_map(fn (User $u) => $u->id, $sellers), $this->takenAt);
    }

    // ---- the numbers ----------------------------------------------------------------------

    public function test_a_company_with_nothing_reports_true_zeros_and_its_currency(): void
    {
        $seller = $this->company(self::COMPANY_A);

        $this->assertSame(['orders_7d' => 0, 'revenue_7d_cents' => 0, 'low_stock_products' => 0, 'currency' => 'ZAR'], $this->collect($seller));
    }

    public function test_orders_counts_distinct_orders_with_a_live_line_of_the_company_placed_in_the_utc_window(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $product = fn () => $this->makeListing($seller);
        $this->order([[$product(), 1]], '2026-10-29 00:00:00');                    // exactly the window start: in
        $this->order([[$product(), 1]], '2026-11-04 23:59:59');                    // last second: in
        $this->order([[$product(), 1], [$product(), 2]], '2026-11-02 12:00:00');   // two lines, one order: counted once
        $this->order([[$product(), 1]], '2026-10-28 23:59:59');                    // before the window
        $this->order([[$product(), 1]], '2026-11-05 00:00:00');                    // exactly taken_at: the next window's
        $this->order([[$product(), 1]], '2026-11-03 12:00:00', [0 => OrderStatus::Cancelled]);          // fully cancelled
        $this->order([[$product(), 1], [$product(), 1]], '2026-11-03 13:00:00', [0 => OrderStatus::Cancelled]); // one live line left: counts

        $this->assertSame(4, $this->collect($seller)['orders_7d']);
    }

    public function test_a_mixed_seller_order_counts_once_for_each_company_and_only_its_own_lines_earn(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $this->order(
            [[$this->makeListing($a, ['price' => 100]), 1], [$this->makeListing($b, ['price' => 900]), 1]],
            '2026-11-02 12:00:00',
            [OrderStatus::Delivered, OrderStatus::Delivered],
        );

        $forA = $this->collect($a);
        $forB = $this->collect($b);

        $this->assertSame(1, $forA['orders_7d']);
        $this->assertSame(10000, $forA['revenue_7d_cents'], 'not the order total, only A\'s own delivered line');
        $this->assertSame(1, $forB['orders_7d']);
        $this->assertSame(90000, $forB['revenue_7d_cents']);
    }

    public function test_revenue_is_delivered_non_cancelled_lines_net_of_their_promo_share_in_integer_cents(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'TWENTY', 'discount_value' => 20]);
        $this->order(
            [[$this->makeListing($a, ['price' => 100]), 1], [$this->makeListing($b, ['price' => 100]), 1]],
            '2026-11-02 12:00:00',
            [OrderStatus::Delivered, OrderStatus::Delivered],
            ['promo_code' => 'TWENTY'],
        );
        $this->order([[$this->makeListing($a, ['price' => '19.99']), 3]], '2026-11-03 12:00:00', [OrderStatus::Delivered]);

        $forA = $this->collect($a);

        $this->assertSame(8000 + 5997, $forA['revenue_7d_cents'], 'A carries the whole 20 discount on the first order; 3 x 19.99 on the second');
        $this->assertIsInt($forA['revenue_7d_cents']);
        $this->assertSame(10000, $this->collect($b)['revenue_7d_cents'], 'B is untouched by A\'s promo');
    }

    public function test_only_delivered_lines_earn_and_only_inside_the_window(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $line = fn (int $price) => [$this->makeListing($seller, ['price' => $price]), 1];
        $this->order([$line(100)], '2026-11-02 10:00:00', [OrderStatus::Delivered]);      // counts
        $this->order([$line(200)], '2026-11-02 10:00:00', [OrderStatus::Pending]);
        $this->order([$line(400)], '2026-11-02 10:00:00', [OrderStatus::Processing]);
        $this->order([$line(800)], '2026-11-02 10:00:00', [OrderStatus::Shipped]);
        $this->order([$line(1600)], '2026-11-02 10:00:00', [OrderStatus::Cancelled]);
        $this->order([$line(3200)], '2026-10-28 23:59:59', [OrderStatus::Delivered]);     // placed before the window
        $this->order([$line(6400)], '2026-11-05 00:00:00', [OrderStatus::Delivered]);     // placed at taken_at
        $this->order([$line(12800)], '2026-10-29 00:00:00', [OrderStatus::Delivered]);    // window start: counts

        $this->assertSame(10000 + 1280000, $this->collect($seller)['revenue_7d_cents']);
    }

    public function test_low_stock_is_active_products_under_five_in_stock_in_the_companys_stores(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->makeListing($seller, ['stock' => 0]);
        $this->makeListing($seller, ['stock' => 4]);
        $this->makeListing($seller, ['stock' => 5]);                          // five is not low
        $this->makeListing($seller, ['stock' => 50]);
        $this->makeListing($seller, ['stock' => 1, 'is_active' => false]);    // switched off
        $this->makeListing($seller, ['stock' => 1])->delete();                // archived
        $this->makeListing($seller, ['stock' => 2, 'status' => 'pending_approval']); // same rule as the seller dashboard: still the seller's stock

        $this->assertSame(3, $this->collect($seller)['low_stock_products']);
    }

    public function test_the_low_stock_threshold_is_the_dashboards_own(): void
    {
        $this->assertSame(5, SellerDashboard::LOW_STOCK_BELOW);
    }

    public function test_the_numbers_agree_with_the_seller_dashboard_for_a_seller_whose_orders_are_all_recent(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $other = $this->company(self::COMPANY_B);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TEN', 'discount_value' => 10]);
        $this->order([[$this->makeListing($seller, ['price' => '120.50', 'stock' => 30]), 2], [$this->makeListing($other, ['price' => 70]), 1]], '2026-11-01 08:00:00', [OrderStatus::Delivered, OrderStatus::Delivered], ['promo_code' => 'TEN']);
        $this->order([[$this->makeListing($seller, ['price' => 33, 'stock' => 3]), 1]], '2026-11-02 08:00:00', [OrderStatus::Shipped]);
        $this->order([[$this->makeListing($seller, ['price' => 10, 'stock' => 9]), 1]], '2026-11-03 08:00:00', [OrderStatus::Cancelled]);
        $this->order([[$this->makeListing($seller, ['price' => 15.15, 'stock' => 9]), 4]], '2026-11-04 08:00:00', [OrderStatus::Delivered]);
        $this->makeListing($seller, ['stock' => 1]);

        $summary = app(SellerDashboard::class)->summary($seller);
        $metrics = $this->collect($seller);

        $this->assertSame($summary['totalOrders'], $metrics['orders_7d']);
        $this->assertSame($summary['revenueByCurrency']['ZAR'], $metrics['revenue_7d_cents']);
        $this->assertSame($summary['lowStockCount'], $metrics['low_stock_products']);
        $this->assertSame(3, $metrics['orders_7d']);
        $this->assertGreaterThan(0, $metrics['revenue_7d_cents']);
    }

    public function test_money_is_published_in_the_storefront_currency_with_no_analytics_setting_at_all(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);

        foreach ([null, '', '  '] as $blank) {
            config(['analytics.currency' => $blank]);
            $metrics = $this->collect($seller);

            $this->assertSame(10000, $metrics['revenue_7d_cents'], var_export($blank, true));
            $this->assertSame('ZAR', $metrics['currency']);
            $this->assertSame(self::CONTRACT, array_keys($metrics));
        }
    }

    public function test_an_empty_company_reports_zero_in_the_current_storefront_currency(): void
    {
        config(['shop.currency' => 'EUR']);

        $this->assertSame(['orders_7d' => 0, 'revenue_7d_cents' => 0, 'low_stock_products' => 0, 'currency' => 'EUR'], $this->collect($this->company(self::COMPANY_A)));
    }

    public function test_history_in_one_currency_is_published_in_that_currency_after_the_storefront_changes(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        config(['shop.currency' => 'EUR']);

        $metrics = $this->collect($seller);

        $this->assertSame(10000, $metrics['revenue_7d_cents']);
        $this->assertSame('ZAR', $metrics['currency'], 'the order, not today\'s setting, says what the money is');
    }

    public function test_a_company_whose_delivered_lines_span_currencies_publishes_no_money_and_logs_a_warning(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $listing = $this->makeListing($seller, ['price' => 100, 'stock' => 50]);
        $this->order([[$listing, 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        config(['shop.currency' => 'EUR']);
        $this->order([[$listing, 1]], '2026-11-03 10:00:00', [OrderStatus::Delivered]);

        Log::spy();
        $metrics = $this->collect($seller);

        $this->assertSame(['orders_7d' => 2, 'low_stock_products' => 0], $metrics);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => str_contains($message, 'several currencies')
            && ($context['currencies'] ?? null) === ['EUR', 'ZAR']);
    }

    public function test_another_currency_only_matters_to_the_company_that_has_it(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $listingA = $this->makeListing($a, ['price' => 100, 'stock' => 50]);
        $listingB = $this->makeListing($b, ['price' => 200, 'stock' => 50]);
        $this->order([[$listingA, 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        $this->order([[$listingB, 1]], '2026-11-02 11:00:00', [OrderStatus::Delivered]);
        config(['shop.currency' => 'EUR']);
        $this->order([[$listingA, 1]], '2026-11-03 10:00:00', [OrderStatus::Delivered]);

        $this->assertArrayNotHasKey('revenue_7d_cents', $this->collect($a));
        $this->assertSame(['orders_7d' => 1, 'revenue_7d_cents' => 20000, 'low_stock_products' => 0, 'currency' => 'ZAR'], $this->collect($b));
    }

    public function test_currencies_of_undelivered_or_out_of_window_lines_do_not_count_as_mixed(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $listing = $this->makeListing($seller, ['price' => 100, 'stock' => 50]);
        $this->order([[$listing, 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        config(['shop.currency' => 'EUR']);
        $this->order([[$listing, 1]], '2026-11-03 10:00:00', [OrderStatus::Shipped]);          // not delivered
        $this->order([[$listing, 1]], '2026-10-01 10:00:00', [OrderStatus::Delivered]);        // outside the window

        $metrics = $this->collect($seller);

        $this->assertSame(10000, $metrics['revenue_7d_cents']);
        $this->assertSame('ZAR', $metrics['currency']);
    }

    public function test_an_analytics_override_that_agrees_with_the_orders_is_published(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        config(['analytics.currency' => ' zar ']);

        $this->assertSame('ZAR', $this->collect($seller)['currency']);
    }

    public function test_an_analytics_override_never_relabels_money_in_another_currency(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        config(['analytics.currency' => 'USD']);

        Log::spy();
        $metrics = $this->collect($seller);

        $this->assertSame(['orders_7d', 'low_stock_products'], array_keys($metrics));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_invalid_analytics_override_leaves_money_out_with_a_warning(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);

        Log::spy();

        foreach (['RAND', 'R', '$', 'ZA1', 'zr'] as $bad) {
            config(['analytics.currency' => $bad]);
            $metrics = $this->collect($seller);

            $this->assertSame(['orders_7d', 'low_stock_products'], array_keys($metrics), $bad);
            $this->assertSame(1, $metrics['orders_7d']);
        }

        Log::shouldHaveReceived('warning')->times(5);
    }

    public function test_only_contract_keys_are_ever_produced_in_the_contract_order(): void
    {
        $this->assertSame(self::CONTRACT, array_keys($this->collect($this->company(self::COMPANY_A))));
    }

    public function test_nothing_but_whole_numbers_and_the_currency_code_is_produced(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => '12.34', 'stock' => 1]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);

        foreach ($this->collect($seller) as $key => $value) {
            $key === 'currency' ? $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $value) : $this->assertIsInt($value, $key);
        }
    }

    // ---- UTC windows, whatever the app timezone is ----------------------------------------

    public function test_the_window_is_the_utc_window_even_when_the_app_runs_in_another_timezone(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $seller = $this->company(self::COMPANY_A);
        $line = fn () => [$this->makeListing($seller, ['price' => 10]), 1];
        $this->order([$line()], '2026-11-04 22:30:00', [OrderStatus::Delivered]);   // 00:30 on 5 Nov local, still before taken_at in UTC: in
        $this->order([$line()], '2026-11-04 22:59:59', [OrderStatus::Delivered]);   // in
        $this->order([$line()], '2026-11-05 00:30:00', [OrderStatus::Delivered]);   // 02:30 local, after taken_at: out
        $this->order([$line()], '2026-10-28 23:30:00', [OrderStatus::Delivered]);   // 01:30 on 29 Oct local, before the window in UTC: out
        $this->order([$line()], '2026-10-29 00:30:00', [OrderStatus::Delivered]);   // in

        $metrics = $this->collect($seller);

        $this->assertSame(3, $metrics['orders_7d']);
        $this->assertSame(3000, $metrics['revenue_7d_cents']);
    }

    // ---- company scoping: never another company's rows -------------------------------------

    public function test_each_company_gets_only_its_own_stores_lines_and_products(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $this->order([[$this->makeListing($a, ['price' => 100, 'stock' => 20]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        foreach (range(1, 3) as $i) {
            $this->order([[$this->makeListing($b, ['price' => 1000, 'stock' => 20]), 1]], '2026-11-0'.$i.' 10:00:00', [OrderStatus::Delivered]);
        }
        $this->makeListing($a, ['stock' => 1]);
        $this->makeListing($b, ['stock' => 1]);
        $this->makeListing($b, ['stock' => 2]);

        $companies = app(CompanyDirectory::class)->group([$a->id, $b->id]);
        $forA = app(MetricsCollector::class)->collect($companies[self::COMPANY_A], $this->takenAt);
        $forB = app(MetricsCollector::class)->collect($companies[self::COMPANY_B], $this->takenAt);

        $this->assertSame(['orders_7d' => 1, 'revenue_7d_cents' => 10000, 'low_stock_products' => 1, 'currency' => 'ZAR'], $forA);
        $this->assertSame(['orders_7d' => 3, 'revenue_7d_cents' => 300000, 'low_stock_products' => 2, 'currency' => 'ZAR'], $forB);
    }

    public function test_a_companys_figures_do_not_change_when_another_company_sells_more(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $this->order([[$this->makeListing($a, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        $before = $this->collect($a);

        $this->order([[$this->makeListing($b, ['price' => 5000, 'stock' => 1]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        $this->makeListing($b, ['stock' => 0]);

        $this->assertSame($before, $this->collect($a));
    }

    public function test_an_empty_seller_list_is_not_everyone(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $this->order([[$this->makeListing($seller, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);

        $this->assertSame([], app(MetricsCollector::class)->collect([], $this->takenAt));
    }

    public function test_a_buyer_in_company_a_buying_from_company_b_counts_for_b_only(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        $order = $this->placeOrder($a, [[$this->makeListing($b, ['price' => 100]), 1]]);   // the seller $a is the buyer here
        $order->forceFill(['created_at' => $this->at('2026-11-02 10:00:00')])->save();
        $order->items[0]->update(['status' => OrderStatus::Delivered]);

        $this->assertSame(0, $this->collect($a)['orders_7d'], 'buying is not selling');
        $this->assertSame(0, $this->collect($a)['revenue_7d_cents']);
        $this->assertSame(1, $this->collect($b)['orders_7d']);
    }

    public function test_lines_are_matched_on_the_checkout_seller_snapshot_so_a_deleted_listing_keeps_its_history(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $product = $this->makeListing($seller, ['price' => 100]);
        $this->order([[$product, 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);

        $product->delete();
        $product->forceDelete();

        $metrics = $this->collect($seller);
        $this->assertSame(1, $metrics['orders_7d']);
        $this->assertSame(10000, $metrics['revenue_7d_cents']);
    }

    // ---- who counts as a company -----------------------------------------------------------

    public function test_snapshots_lists_each_linked_store_owners_company_once_and_skips_unlinked_sellers(): void
    {
        $a1 = $this->company(self::COMPANY_A);
        $a2 = $this->colleague($a1);
        $b = $this->company(self::COMPANY_B);
        $unlinked = $this->company(null);
        $this->order([[$this->makeListing($a1, ['price' => 100]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        $this->makeListing($a2, ['stock' => 1]);
        $this->makeListing($b);
        $this->order([[$this->makeListing($unlinked, ['price' => 999]), 1]], '2026-11-02 10:00:00', [OrderStatus::Delivered]);
        $this->makeBuyer();   // a user who owns no store is nobody's company

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($snapshots));
        $this->assertSame(['orders_7d' => 1, 'revenue_7d_cents' => 10000, 'low_stock_products' => 1, 'currency' => 'ZAR'], $snapshots[self::COMPANY_A], 'both stores of the company, summed');
        $this->assertSame(0, $snapshots[self::COMPANY_B]['orders_7d']);
        $this->assertStringNotContainsString('999', json_encode($snapshots), 'the unlinked seller\'s sale is nowhere');
    }

    public function test_snapshots_can_be_limited_to_one_company(): void
    {
        $this->makeListing($this->company(self::COMPANY_A));
        $this->makeListing($this->company(self::COMPANY_B));

        $this->assertSame([self::COMPANY_B], array_keys(app(MetricsCollector::class)->snapshots($this->takenAt, strtoupper(self::COMPANY_B))));
        $this->assertSame([], app(MetricsCollector::class)->snapshots($this->takenAt, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    }

    public function test_the_company_is_the_current_team_then_the_personal_team_and_nothing_is_switched(): void
    {
        $colleague = $this->company(self::COMPANY_A);
        $both = User::factory()->withPersonalTeam()->create();
        $both->personalTeam()->forceFill(['infodot_team_uuid' => self::COMPANY_B])->save();
        $both->forceFill(['current_team_id' => $colleague->current_team_id])->save();
        $fallback = User::factory()->withPersonalTeam()->create();
        $fallback->personalTeam()->forceFill(['infodot_team_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'])->save();
        $side = Team::forceCreate(['user_id' => $fallback->id, 'name' => 'Side project', 'personal_team' => false]);
        $fallback->forceFill(['current_team_id' => $side->id])->save();
        $none = User::factory()->withPersonalTeam()->create();
        $none->personalTeam()->forceFill(['infodot_team_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])->save();
        $none->forceFill(['current_team_id' => null])->save();
        $blank = $this->company('');

        $groups = app(CompanyDirectory::class)->group([$colleague->id, $both->id, $fallback->id, $none->id, $blank->id]);

        $this->assertSame([
            self::COMPANY_A => [$colleague->id, $both->id],
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd' => [$fallback->id],
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' => [$none->id],
        ], $groups);
        $this->assertSame($side->id, $fallback->fresh()->current_team_id, 'resolving a company must not switch the user\'s team');
        $this->assertNull($none->fresh()->current_team_id);
    }

    public function test_a_seller_who_moved_to_another_company_is_counted_with_their_current_one_only(): void
    {
        $seller = $this->company(self::COMPANY_A);
        $newTeam = Team::forceCreate(['user_id' => $seller->id, 'name' => 'New company', 'personal_team' => false]);
        $newTeam->forceFill(['infodot_team_uuid' => self::COMPANY_B])->save();
        $seller->forceFill(['current_team_id' => $newTeam->id])->save();

        $this->assertSame([self::COMPANY_B], array_keys(app(MetricsCollector::class)->snapshots($this->takenAt)));
    }

    public function test_stores_of_another_company_are_never_mixed_in_even_with_equal_names(): void
    {
        $a = $this->company(self::COMPANY_A);
        $b = $this->company(self::COMPANY_B);
        Store::query()->whereKey($b->store->id)->update(['name' => $a->store->name]);
        $this->makeListing($b, ['stock' => 0]);

        $this->assertSame(0, $this->collect($a)['low_stock_products']);
        $this->assertSame(1, $this->collect($b)['low_stock_products']);
    }
}
