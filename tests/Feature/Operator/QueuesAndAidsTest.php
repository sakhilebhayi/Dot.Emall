<?php

namespace Tests\Feature\Operator;

use App\Enums\StoreStatus;
use App\Models\ListingFlagDismissal;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReconcileRun;
use App\Models\ScheduledTaskRun;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Operator\ListingFlags;
use App\Services\Operator\NeedsAPerson;
use App\Services\Operator\OperatorMetrics;
use App\Services\Operator\ReconcileRuns;
use App\Services\Operator\TaskRunRecorder;
use App\Support\Shop;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

class QueuesAndAidsTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- home --------------------------------------------------------------------------------------------------------

    public function test_the_home_lists_what_waits_with_counts_and_the_oldest_age(): void
    {
        $w = $this->operatorWorld();
        $w['pending']->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $items = collect(app(NeedsAPerson::class)->items())->keyBy('key');

        $this->assertSame(1, $items['products']['count']);
        $this->assertSame(1, $items['returns']['count']);
        $this->assertSame(1, $items['reviews']['count']);
        $this->assertSame(1, $items['questions']['count']);
        $this->assertSame(1, $items['reports']['count']);
        $this->assertSame(0, $items['stores']['count']);
        $this->assertSame('clear', $items['stores']['state']);
        $this->assertSame('unknown', $items['ledger']['state'], 'a reconciliation that never ran is unknown, not fine');
        $this->assertSame(3, (int) $items['products']['oldest']->diffInDays(now()));

        $this->actingAs($w['operator'])->get(route('operator.home'))->assertOk()
            ->assertSee('Listings awaiting approval')->assertSee('Returns escalated to you')->assertSee('Oldest')->assertSee('3 days')
            ->assertSee('Not known yet')->assertSee('Nothing waiting');
    }

    public function test_the_home_says_so_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->makeOperator())->get(route('operator.home'))->assertOk()->assertSee('Nothing is waiting for you');
    }

    public function test_the_home_costs_the_same_number_of_queries_however_long_the_queues_are(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('operator.home'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count();
        $few = $count();

        for ($i = 0; $i < 12; $i++) {
            $this->makeListing($w['seller'], ['status' => 'pending_approval']);
            \App\Models\ProductQuestion::factory()->held()->create(['product_id' => $w['live']->id, 'user_id' => $this->makeBuyer()->id]);
        }

        $this->assertSame($few, $count(), 'the home page must not issue more queries as the queues grow');
    }

    // ---- filters -----------------------------------------------------------------------------------------------------

    public function test_the_listing_queue_filters_by_status_name_and_category(): void
    {
        $w = $this->operatorWorld();
        $category = StoreCategory::query()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $w['pending']->forceFill(['category_id' => $category->id])->save();
        $this->actingAs($w['operator']);

        $this->get(route('operator.products.index'))->assertSee('Pendingware kettle')->assertDontSee('Liveware toaster');
        $this->get(route('operator.products.index', ['status' => 'approved']))->assertSee('Liveware toaster')->assertDontSee('Pendingware kettle');
        $this->get(route('operator.products.index', ['status' => 'all']))->assertSee('Liveware toaster')->assertSee('Pendingware kettle');
        $this->get(route('operator.products.index', ['status' => 'all', 'q' => 'kettle']))->assertSee('Pendingware kettle')->assertDontSee('Liveware toaster');
        $this->get(route('operator.products.index', ['status' => 'all', 'category' => $category->id]))->assertSee('Pendingware kettle')->assertDontSee('Liveware toaster');
        $this->get(route('operator.products.show', $w['pending']))->assertOk()->assertSee('Pendingware kettle')->assertSee('Ridgeline Outfitters')->assertSee('Reject listing');
    }

    public function test_the_store_queue_filters_by_status_and_shows_the_seller_data_labelled_claimed(): void
    {
        $w = $this->operatorWorld();
        $store = $w['seller']->store;
        $store->forceFill(['status' => StoreStatus::PendingReview, 'is_active' => false, 'submitted_at' => now()->subDay(), 'registration_number' => '2019/123456/07'])->save();
        $this->actingAs($w['operator']);

        $this->get(route('operator.stores.index'))->assertOk()->assertSee('Ridgeline Outfitters');
        $this->get(route('operator.stores.index', ['status' => 'active']))->assertOk()->assertDontSee('Ridgeline Outfitters');
        $page = $this->get(route('operator.stores.show', $store))->assertOk();
        $page->assertSee('2019/123456/07 (claimed)')->assertSee('Claimed by the seller, not checked')->assertSee('Approve store')->assertSee('Send back with a reason');
        $page->assertDontSee('Verified seller');
    }

    public function test_reports_filter_by_status_type_and_reason_and_show_counts_of_people(): void
    {
        $w = $this->operatorWorld();
        \App\Models\ContentReport::query()->create(['reportable_type' => 'review', 'reportable_id' => $w['reported']->id, 'reporter_id' => $this->makeBuyer()->id, 'reason' => 'spam']);
        $this->actingAs($w['operator']);

        $this->get(route('operator.reports'))->assertOk()->assertSee('2 people')->assertSee('2 reports')->assertSee('fake')->assertSee('Best shop, buy from my cousin');
        $this->get(route('operator.reports', ['type' => 'store']))->assertOk()->assertDontSee('Best shop, buy from my cousin');
        $this->get(route('operator.reports', ['status' => 'settled']))->assertOk()->assertDontSee('Best shop, buy from my cousin');
        $this->get(route('operator.reports', ['reason' => 'spam']))->assertOk()->assertSee('Best shop');
        $this->get(route('operator.reports.show', ['type' => 'review', 'id' => $w['reported']->id]))->assertOk()->assertSee('Written by the seller')->assertSee('Your decision');
    }

    public function test_the_return_queue_shows_escalated_returns_with_both_sides(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $this->get(route('operator.returns.index'))->assertOk()->assertSee('Buyer disputed a decline');
        $this->get(route('operator.returns.show', $w['return']))->assertOk()->assertSee('It will not switch on')->assertSee('It was dead when it arrived')->assertSee('Looks like water damage');
        $this->get(route('operator.returns.index', ['tab' => 'settled']))->assertOk()->assertSee('Nothing settled yet');
    }

    public function test_payments_desk_flags_an_order_paid_twice_and_lists_events(): void
    {
        $w = $this->operatorWorld();
        $first = \App\Models\Payment::query()->first();
        $dup = $first->replicate();
        $dup->reference = 'DUP-'.$first->reference;
        $dup->provider_transaction_id = null;
        $dup->save();
        \App\Models\PaymentEvent::query()->create(['source' => 'webhook', 'event' => 'charge.success', 'reference' => $dup->reference, 'outcome' => 'duplicate', 'payment_id' => $dup->id, 'order_id' => $first->order_id]);

        $this->actingAs($w['operator'])->get(route('operator.payments.index'))->assertOk()->assertSee('Paid more than once')->assertSee('2 payments');
        $this->get(route('operator.payments.events', ['outcome' => 'duplicate']))->assertOk()->assertSee($dup->reference);
        $this->get(route('operator.payments.events', ['outcome' => 'unmatched']))->assertOk()->assertDontSee($dup->reference);
    }

    // ---- rule flags ---------------------------------------------------------------------------------------------------

    public function test_price_anomalies_are_flagged_against_the_category_median_with_the_rule_shown(): void
    {
        $w = $this->operatorWorld();
        $category = StoreCategory::query()->create(['name' => 'Headphones', 'slug' => 'headphones']);

        foreach ([900, 950, 1000, 1050, 1100, 1000] as $price) {
            $this->makeListing($w['seller'], ['category_id' => $category->id, 'price' => $price]);
        }

        $cheap = $this->makeListing($w['seller'], ['category_id' => $category->id, 'price' => 90, 'name' => 'Suspiciously cheap headphones']);
        $normal = $this->makeListing($w['seller'], ['category_id' => $category->id, 'price' => 980, 'name' => 'Ordinary headphones']);

        $flags = app(ListingFlags::class)->all('price_anomaly');

        $this->assertTrue($flags->contains(fn ($f) => $f['product']->id === $cheap->id));
        $this->assertFalse($flags->contains(fn ($f) => $f['product']->id === $normal->id));
        $this->assertStringContainsString('category median', $flags->first(fn ($f) => $f['product']->id === $cheap->id)['detail']);

        $this->actingAs($w['operator'])->get(route('operator.flags.index'))->assertOk()->assertSee('Suspiciously cheap headphones')->assertSee('Price far from the category median')->assertSee('The rules in force');
    }

    public function test_the_prohibited_terms_list_is_configurable_and_matches_whole_words(): void
    {
        $w = $this->operatorWorld();
        config(['emall.prohibited_terms' => ['replica', 'vodka']]);
        $bad = $this->makeListing($w['seller'], ['name' => 'Designer handbag replica', 'description' => 'Looks real']);
        $ok = $this->makeListing($w['seller'], ['name' => 'Replicator cable', 'description' => 'A vodkaless cable']);

        $flags = app(ListingFlags::class)->all('prohibited_term');

        $this->assertTrue($flags->contains(fn ($f) => $f['product']->id === $bad->id));
        $this->assertFalse($flags->contains(fn ($f) => $f['product']->id === $ok->id), 'whole words only');
        $this->assertStringContainsString('replica', $flags->first()['detail']);
    }

    public function test_a_brand_new_store_with_many_expensive_listings_is_flagged(): void
    {
        $w = $this->operatorWorld();
        $new = $this->makeSeller(['name' => 'Fresh Imports']);
        $new->store->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        foreach (range(1, 5) as $i) {
            $this->makeListing($new, ['price' => 8000 + $i, 'name' => "Gadget {$i}"]);
        }

        $flags = app(ListingFlags::class)->all('new_store_value');

        $this->assertCount(5, $flags);
        $this->assertStringContainsString('Fresh Imports', $flags->first()['detail']);
        $this->assertCount(0, $flags->filter(fn ($f) => $f['product']->store_id === $w['seller']->store->id));
    }

    public function test_dismissing_a_flag_needs_a_note_is_audited_and_stays_dismissed_for_that_rule(): void
    {
        $w = $this->operatorWorld();
        config(['emall.prohibited_terms' => ['kettle']]);
        $this->actingAs($w['operator']);

        $this->assertTrue(app(ListingFlags::class)->all('prohibited_term')->contains(fn ($f) => $f['product']->id === $w['pending']->id));

        $this->post(route('operator.flags.dismiss', $w['pending']), ['rule' => 'prohibited_term', 'note' => ''])->assertSessionHasErrors();
        $this->post(route('operator.flags.dismiss', $w['pending']), ['rule' => 'prohibited_term', 'note' => 'It is an electric kettle, allowed.'])->assertRedirect();

        $this->assertSame(1, ListingFlagDismissal::query()->count());
        $this->assertFalse(app(ListingFlags::class)->all('prohibited_term')->contains(fn ($f) => $f['product']->id === $w['pending']->id));
        $this->assertSame('It is an electric kettle, allowed.', \App\Models\OperatorAuditEntry::query()->where('action', 'listing.flag_dismiss')->first()->reason);
        $this->assertSame('pending_approval', $w['pending']->fresh()->status, 'a flag never changes a listing by itself');
    }

    public function test_a_flag_changes_nothing_by_itself(): void
    {
        $w = $this->operatorWorld();
        config(['emall.prohibited_terms' => ['toaster']]);

        $this->assertNotEmpty(app(ListingFlags::class)->all());
        $this->actingAs($w['operator'])->get(route('operator.flags.index'))->assertOk();

        $this->assertSame('approved', $w['live']->fresh()->status);
        $this->assertTrue((bool) $w['live']->fresh()->is_active);
    }

    // ---- metrics --------------------------------------------------------------------------------------------------------

    public function test_metrics_say_no_data_yet_instead_of_zero_on_an_empty_marketplace(): void
    {
        Cache::flush();
        $page = $this->actingAs($this->makeOperator())->get(route('operator.metrics'));

        $page->assertOk()->assertSee('No data yet')->assertSee('Gross merchandise value')->assertSee('Return rate')->assertSee('Cart abandonment')
            ->assertSee('Seller fulfilment')->assertSee('Dispute rate')->assertSee('Active sellers')->assertSee('Active products');
        $page->assertDontSee('R0.00');
        $page->assertDontSee('<svg class="op-chart"', false);

        foreach (app(OperatorMetrics::class)->report()['metrics'] as $m) {
            if (in_array($m->key, ['active_products', 'active_sellers'], true)) {
                continue;
            }
            $this->assertFalse($m->hasData(), $m->key.' must be "no data yet" with nothing in the shop');
            $this->assertNull($m->value);
        }
    }

    public function test_metrics_are_computed_from_seeded_rows_with_their_definitions(): void
    {
        Cache::flush();
        $w = $this->operatorWorld();
        $total = Order::query()->first()->totalCents();

        $report = app(OperatorMetrics::class)->report('30d');
        $by = collect($report['metrics'])->keyBy('key');

        $this->assertSame($total, $by['gmv']->value);
        $this->assertSame($total, $by['aov']->value);
        $this->assertSame(1, $by['return_rate']->numerator);
        $this->assertSame(1, $by['return_rate']->denominator);
        $this->assertSame(1.0, $by['dispute_rate']->value, 'the one return reached the operator');
        $this->assertSame(1, $by['active_sellers']->value);
        $this->assertSame(1, $by['active_products']->value, 'the pending listing is not active');

        $this->actingAs($w['operator'])->get(route('operator.metrics', ['period' => '30d']))->assertOk()
            ->assertSee(Shop::money($total))->assertSee('The same rule as the store page uses')->assertSee('Return requests opened in the period')->assertSee('Show as a table')
            ->assertSee('<svg class="op-chart"', false);
    }

    public function test_metrics_period_selector_changes_the_window(): void
    {
        Cache::flush();
        $this->operatorWorld();
        Carbon::setTestNow(now()->addDays(60));
        Cache::flush();

        $this->assertNull(collect(app(OperatorMetrics::class)->report('7d')['metrics'])->keyBy('key')['gmv']->value, 'the order is older than 7 days');
        $this->assertNotNull(collect(app(OperatorMetrics::class)->report('90d')['metrics'])->keyBy('key')['gmv']->value);
        $this->actingAs($this->makeOperator())->get(route('operator.metrics', ['period' => 'bogus']))->assertOk()->assertSee('Last 30 days');
    }

    public function test_metrics_cost_the_same_queries_for_few_and_many_orders_and_are_cached(): void
    {
        $w = $this->operatorWorld();
        $metrics = app(OperatorMetrics::class);

        $queries = function () use ($metrics) {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $metrics->report('30d');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $queries();

        foreach (range(1, 6) as $i) {
            $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($w['seller'], ['price' => 50 + $i]), 1]]);
            $this->payOrder($order);
        }

        $this->assertSame($few, $queries());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $metrics->report('30d');
        $this->assertCount(0, DB::getQueryLog(), 'the second call within a minute reads the cache');
    }

    public function test_the_csv_export_is_operator_only_and_carries_definitions_not_personal_data(): void
    {
        Cache::flush();
        $w = $this->operatorWorld();

        $this->get(route('operator.metrics.export'))->assertRedirect(route('login'));
        $this->actingAs($w['buyer'])->get(route('operator.metrics.export'))->assertForbidden();
        $this->actingAs($w['seller'])->get(route('operator.metrics.export'))->assertForbidden();

        $csv = $this->actingAs($w['operator'])->get(route('operator.metrics.export', ['period' => '90d']))->getContent();

        $this->assertStringContainsString('metric,key,value,unit,numerator,denominator,status,period_from,period_to,definition', $csv);
        $this->assertStringContainsString('Gross merchandise value', $csv);
        $this->assertStringContainsString('gmv', $csv);
        $this->assertStringNotContainsString($w['buyer']->email, $csv);
        $this->assertStringNotContainsString($w['seller']->email, $csv);
        $this->assertStringContainsString('no data yet', $csv, 'a metric without data is marked, not zero');
    }

    // ---- ledger ---------------------------------------------------------------------------------------------------------

    public function test_the_ledger_page_stores_reconcile_runs_and_highlights_mismatches(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator'])->get(route('operator.ledger.index'))->assertOk()->assertSee('It has not run yet');

        $this->post(route('operator.ledger.run'))->assertRedirect(route('operator.ledger.index'));
        $run = ReconcileRun::query()->latest('id')->first();
        $this->assertSame('operator', $run->trigger);
        $this->assertSame(1, $run->checked);
        $this->assertSame(0, $run->mismatch_count);
        $this->assertNotNull(\App\Models\OperatorAuditEntry::query()->where('action', 'reconcile.run')->first());
        $this->get(route('operator.ledger.index'))->assertSee('Everything agreed');

        Order::query()->update(['total' => DB::raw('total + 5')]);
        $this->post(route('operator.ledger.run'));

        $latest = ReconcileRun::query()->latest('id')->first();
        $this->assertGreaterThan(0, $latest->mismatch_count);
        $this->get(route('operator.ledger.index'))->assertOk()->assertSee('at the last run')->assertSee($latest->mismatches[0]['check']);

        $items = collect(app(NeedsAPerson::class)->items())->keyBy('key');
        $this->assertSame($latest->mismatch_count, $items['ledger']['count']);
        $this->assertSame('waiting', $items['ledger']['state']);
    }

    public function test_the_reconcile_command_stores_what_it_found(): void
    {
        $this->operatorWorld();

        $this->assertSame(0, Artisan::call('emall:reconcile'));

        $run = app(ReconcileRuns::class)->latest();
        $this->assertSame('command', $run->trigger);
        $this->assertSame(1, $run->checked);
    }

    // ---- health ---------------------------------------------------------------------------------------------------------

    public function test_health_says_unknown_where_there_is_no_record_and_ok_after_a_recorded_run(): void
    {
        $operator = $this->makeOperator();
        $this->actingAs($operator)->get(route('operator.health'))->assertOk()->assertSee('Never recorded')->assertSee('Unknown')->assertSee('emall:reconcile');
        $this->assertSame(0, ScheduledTaskRun::query()->count());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'emall:reconcile'));
        $this->assertNotNull($event, 'the nightly reconciliation is scheduled');
        event(new ScheduledTaskFinished($event, 0.2));

        $run = ScheduledTaskRun::query()->first();
        $this->assertSame('emall:reconcile', $run->task);
        $this->assertSame('success', $run->status);
        $this->assertSame('emall:reconcile', TaskRunRecorder::name("'/usr/bin/php' 'artisan' emall:reconcile --days=3"));
    }

    public function test_health_flags_failed_jobs_and_unmatched_payment_events(): void
    {
        $operator = $this->makeOperator();
        DB::table('failed_jobs')->insert(['uuid' => 'abc', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        \App\Models\PaymentEvent::query()->create(['source' => 'webhook', 'event' => 'charge.success', 'outcome' => 'unmatched', 'reason' => 'unknown_reference']);

        $page = $this->actingAs($operator)->get(route('operator.health'))->assertOk();
        $page->assertSee('Failed jobs')->assertSee('Needs attention')->assertSee('Payment events with no matching payment');
    }
}
