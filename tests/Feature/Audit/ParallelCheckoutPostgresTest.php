<?php

namespace Tests\Feature\Audit;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real concurrency (R-43 backend audit): several PHP processes, each with its own database connection, hit the same
 * rows at the same instant. SQLite serialises writers and ignores FOR UPDATE, so the stock, promo-count, payment and
 * cancellation locking can only be PROVEN on PostgreSQL. Run with `vendor/bin/phpunit -c phpunit.pgsql.xml`; on SQLite
 * it skips.
 *
 * Deliberately NOT RefreshDatabase: that wraps the test in a transaction the child processes cannot see. The rows
 * created here are committed, and removed again in tearDown.
 */
class ParallelCheckoutPostgresTest extends TestCase
{
    /** @var array<int, int> */
    private array $userIds = [];

    /** @var array<int, string> */
    private array $paymentReferences = [];

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
            DB::table('payment_events')->whereIn('reference', $this->paymentReferences)->delete();
            DB::table('payments')->whereIn('reference', $this->paymentReferences)->delete();
            DB::table('notifications')->whereIn('notifiable_id', $this->userIds)->delete();
            // Stores, products, promo codes, orders, lines and carts all cascade from their owner.
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: Product, 2: Store}
     */
    private function seedProduct(int $stock, string $price = '50.00'): array
    {
        $seller = User::factory()->create();
        $store = Store::factory()->create(['owner_id' => $seller->id]);
        $product = Product::factory()->create(['store_id' => $store->id, 'stock' => $stock, 'price' => $price]);
        $this->userIds[] = $seller->id;

        return [$seller, $product, $store];
    }

    /**
     * @return array<int, User>
     */
    private function seedBuyers(int $count, Product $product, int $quantity = 1): array
    {
        $buyers = User::factory()->count($count)->create()->all();

        foreach ($buyers as $buyer) {
            CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => $quantity]);
            $this->userIds[] = $buyer->id;
        }

        return $buyers;
    }

    /**
     * @param  array<int, array<int, string>>  $jobs  each is the worker's arguments, startAt injected at position 1
     * @return array<int, array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 3.0;
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $processes = [];

        foreach ($jobs as $i => $job) {
            // [mode, subject, ...rest] -> mode, subject, startAt, ...rest
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

    public function test_ten_buyers_racing_for_the_last_unit_produce_exactly_one_order_and_never_negative_stock(): void
    {
        [, $product] = $this->seedProduct(1);
        $buyers = $this->seedBuyers(10, $product);

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id], $buyers));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), 'only one buyer can get the single unit: '.json_encode($results));
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(1, OrderItem::where('product_id', $product->id)->count());
        $this->assertSame(1, (int) OrderItem::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_stock_of_five_with_twelve_buyers_of_two_sells_exactly_two_orders(): void
    {
        [, $product] = $this->seedProduct(5);
        $buyers = $this->seedBuyers(12, $product, 2);

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id], $buyers));

        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(4, (int) OrderItem::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_a_promo_code_with_one_use_left_is_redeemed_by_exactly_one_of_many_simultaneous_buyers(): void
    {
        [, $product, $store] = $this->seedProduct(50);
        $promo = PromoCode::factory()->create(['store_id' => $store->id, 'code' => 'ONCEONLY', 'discount_type' => 'fixed', 'discount_value' => 10, 'max_uses' => 1]);
        $buyers = $this->seedBuyers(10, $product);

        $results = $this->race(array_map(fn (User $b) => ['checkout', $b->id, 'ONCEONLY'], $buyers));

        // A rejected promo makes the whole checkout fail (PromoRejectedException is a CheckoutException).
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, (int) $promo->fresh()->used_count);
        $this->assertSame(1, Order::where('promo_code_id', $promo->id)->count());
    }

    public function test_the_webhook_and_the_callback_racing_apply_one_charge_exactly_once(): void
    {
        [, $product] = $this->seedProduct(5, '100.00');
        [$buyer] = $this->seedBuyers(1, $product, 1);
        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street']);
        $order->forceFill(['requires_payment' => true])->save();

        $payment = Payment::create([
            'order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'EMALL-RACE-'.$order->id, 'amount_cents' => 10000,
            'currency' => 'ZAR', 'status' => PaymentAttemptStatus::Pending, 'authorization_url' => 'https://checkout.paystack.com/x',
        ]);
        $this->paymentReferences[] = $payment->reference;

        $jobs = [];
        foreach (['webhook', 'callback', 'webhook', 'callback', 'webhook', 'callback'] as $source) {
            $jobs[] = ['charge', $payment->reference, 10000, 'tx-9001', $source];
        }

        $results = $this->race($jobs);

        $outcomes = array_count_values(array_column($results, 'outcome'));
        $this->assertSame(1, $outcomes['applied'] ?? 0, 'one charge is applied once: '.json_encode($results));
        $this->assertSame(5, $outcomes['duplicate'] ?? 0, json_encode($results));
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, DB::table('payment_events')->where('reference', $payment->reference)->where('outcome', 'applied')->count());
    }

    public function test_two_simultaneous_cancellations_of_one_paid_line_restock_once_and_owe_one_refund(): void
    {
        [, $product] = $this->seedProduct(5, '100.00');
        [$buyer] = $this->seedBuyers(1, $product, 2);
        $order = app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Street']);
        $order->forceFill(['requires_payment' => true, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
        $payment = Payment::create([
            'order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'EMALL-CANCEL-'.$order->id, 'amount_cents' => 20000,
            'currency' => 'ZAR', 'status' => PaymentAttemptStatus::Success,
        ]);
        $this->paymentReferences[] = $payment->reference;
        $line = $order->items()->first();
        $this->assertSame(3, (int) $product->fresh()->stock);

        $this->race([['cancel', $line->id], ['cancel', $line->id], ['cancel', $line->id]]);

        $this->assertSame(5, (int) $product->fresh()->stock, 'stock goes back exactly once');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(20000, (int) Refund::where('payment_id', $payment->id)->sum('amount_cents'), 'the buyer is owed what they paid, once');
    }
}
