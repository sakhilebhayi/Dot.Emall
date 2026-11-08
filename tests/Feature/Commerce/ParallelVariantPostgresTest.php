<?php

namespace Tests\Feature\Commerce;

use App\Models\CartItem;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalogue\ProductVariants;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The same real-concurrency proof as ParallelCheckoutPostgresTest, against VARIANTS: several PHP processes, each with its
 * own connection, hit the same variant rows at the same instant. Skips on SQLite (no row locks, one writer). Rows are
 * committed and removed in tearDown (the children cannot see an open test transaction).
 */
class ParallelVariantPostgresTest extends TestCase
{
    /** @var array<int, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs PostgreSQL: row locks and several real connections.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }

        if (! Schema::hasTable('products')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('products')) {
            DB::table('notifications')->whereIn('notifiable_id', $this->userIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function shirt(int $stockM, int $stockL): array
    {
        $seller = User::factory()->create();
        $this->userIds[] = $seller->id;
        $store = Store::factory()->create(['owner_id' => $seller->id]);
        $product = Product::factory()->create(['store_id' => $store->id, 'stock' => 0, 'price' => '100.00']);
        $service = app(ProductVariants::class);
        $service->defineOptions($product, ['Size' => ['M', 'L']]);
        $m = $service->saveVariant($product, ['Size' => 'M'], ['price' => 100, 'stock' => $stockM]);
        $l = $service->saveVariant($product, ['Size' => 'L'], ['price' => 110, 'stock' => $stockL]);

        return [$product->fresh(), $m, $l];
    }

    /** @return array<int, User> */
    private function buyers(int $count, ProductVariant $variant, int $quantity = 1): array
    {
        $buyers = User::factory()->count($count)->create()->all();

        foreach ($buyers as $buyer) {
            CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => $quantity]);
            $this->userIds[] = $buyer->id;
        }

        return $buyers;
    }

    /**
     * @param  array<int, array<int, string>>  $jobs
     * @return array<int, array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 3.0;
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $processes = [];

        foreach ($jobs as $i => $job) {
            $command = array_merge([PHP_BINARY, base_path('tests/Support/parallel-worker.php'), $job[0], (string) $job[1], (string) $startAt], array_map('strval', array_slice($job, 2)));
            $processes[$i] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
        }

        $results = [];
        foreach ($processes as $i => [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            $line = collect(explode("\n", trim($out)))->last(fn ($l) => str_starts_with($l, '{'));
            $this->assertNotNull($line, "worker {$i} printed no result: ".$out);
            $results[] = json_decode($line, true);
        }

        $this->assertSame([], array_filter($results, fn ($r) => isset($r['crash'])), 'a worker crashed: '.json_encode($results));

        return $results;
    }

    public function test_ten_buyers_racing_for_the_last_unit_of_one_variant_produce_one_order_and_leave_the_other_variant_alone(): void
    {
        [$product, $m, $l] = $this->shirt(1, 5);
        $buyers = $this->buyers(10, $m);

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id], $buyers));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), 'only one buyer gets the single unit: '.json_encode($results));
        $this->assertSame(0, $m->fresh()->stock);
        $this->assertSame(5, $l->fresh()->stock, 'the other variant is untouched');
        $this->assertSame(5, $product->fresh()->stock, 'the product shows the total of its variants');
        $this->assertSame(1, DB::table('stock_movements')->where('variant_id', $m->id)->where('reason', 'sale')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('variant_id', $m->id)->sum('stock_after') < 0 ? 1 : 0);
    }

    public function test_buyers_of_different_variants_all_succeed_and_the_product_total_adds_up(): void
    {
        [$product, $m, $l] = $this->shirt(6, 6);
        $buyers = array_merge($this->buyers(4, $m, 1), $this->buyers(4, $l, 1));

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id], $buyers));

        $this->assertCount(8, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame([2, 2], [$m->fresh()->stock, $l->fresh()->stock]);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(8, DB::table('stock_movements')->where('product_id', $product->id)->where('reason', 'sale')->count());
    }

    public function test_twelve_buyers_of_two_with_five_in_stock_sell_exactly_four_and_never_go_negative(): void
    {
        [$product, $m] = $this->shirt(5, 0);
        $buyers = $this->buyers(12, $m, 2);

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id], $buyers));

        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, $m->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, $m->fresh()->stock);
    }

    public function test_a_seller_stock_edit_racing_with_sales_keeps_every_sale(): void
    {
        [$product, $m] = $this->shirt(10, 0);
        $buyers = $this->buyers(5, $m, 1);
        $jobs = array_map(fn (User $b) => ['checkout', $b->id], $buyers);
        // The seller's form showed 10 and they typed 14 (a restock of 4), at the same instant five buyers each take one.
        $jobs[] = ['setstock', $m->id, 14, 10];

        $results = $this->race($jobs);

        $this->assertCount(6, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(10 - 5 + 4, $m->fresh()->stock, 'five sales and the seller\'s +4 both stand');
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_the_ledger_entry_for_a_paid_order_is_written_once_however_many_processes_try(): void
    {
        [, $m] = $this->shirt(5, 0);
        $buyer = $this->buyers(1, $m, 1)[0];
        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street']);
        Order::query()->whereKey($order->id)->update(['requires_payment' => true, 'payment_status' => 'paid', 'paid_at' => now()]);

        $results = $this->race(array_fill(0, 6, ['ledger', $order->id]));

        $this->assertSame(1, array_sum(array_column($results, 'written')), json_encode($results));
        $this->assertSame(1, LedgerEntry::query()->where('order_id', $order->id)->count());
    }
}
