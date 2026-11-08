<?php

namespace Tests\Feature\Demo;

use App\Models\Address;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Catalogue\ReferencePrice;
use App\Services\Discovery\Recommender;
use App\Services\Discovery\SearchLog;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Ledger\Reconciler;
use App\Support\Actor;
use Database\Seeders\EmallDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmallDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('emall:demo-seed')
            ->expectsOutputToContain('never runs in production')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_seeder_class_also_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        (new EmallDemoSeeder)->run();
    }

    public function test_it_builds_the_marketplace_through_the_real_services(): void
    {
        $this->artisan('emall:demo-seed')->expectsOutputToContain('Guest order link (no sign-in needed): ')->assertExitCode(0);
        $this->assertSame(11, User::query()->where('email', 'like', '%@emall-demo.test')->count());
        $this->assertSame(4, Store::query()->count());
        $this->assertGreaterThanOrEqual(120, Product::query()->count());
        $this->assertGreaterThanOrEqual(30, Order::query()->count());
        $this->assertGreaterThan(0, Review::query()->count());
        $this->assertTrue(User::query()->where('email', 'operator@emall-demo.test')->value('is_platform_operator'));

        // Every order status and payment state the storefront can show is present.
        $statuses = Order::query()->distinct()->pluck('status')->map->value->all();
        foreach (['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'ready_for_collection', 'collected', 'refunded'] as $status) {
            $this->assertContains($status, $statuses, "no order with status {$status}");
        }
        foreach (['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_refunded'] as $state) {
            $this->assertTrue(Order::query()->where('payment_status', $state)->exists(), "no order with payment_status {$state}");
        }

        // Stock and rating aggregates are what the services leave behind, never negative.
        $this->assertSame(0, Product::query()->where('stock', '<', 0)->count());
        $this->assertTrue(Order::query()->whereHas('items')->exists());
        $multiSeller = DB::table('order_items')->selectRaw('order_id, count(distinct seller_id) as sellers')->groupBy('order_id')->get()->max('sellers');
        $this->assertGreaterThanOrEqual(3, $multiSeller);

        // --- the catalogue is on the category tree with brands attributes variants and a search index
        $withCategory = Product::query()->whereNotNull('category_id')->count();
        $this->assertSame(Product::query()->count(), $withCategory);
        $this->assertGreaterThanOrEqual(12, Product::query()->distinct()->count('category_id'), 'products sit in at least 12 categories');
        $this->assertTrue(StoreCategory::query()->where('slug', 'laptops')->whereNotNull('parent_id')->exists(), 'the shared tree was seeded');

        $laptop = Product::query()->where('name', 'like', 'Lenovo IdeaPad Slim 3%')->firstOrFail();
        $this->assertSame('Lenovo', $laptop->brand->name);
        $this->assertEqualsCanonicalizing(['ram', 'storage', 'storage_type', 'processor', 'screen_size', 'operating_system'], $laptop->attributeValues->pluck('key')->all());

        $shirt = Product::query()->where('name', 'Merino Base Layer Tee')->firstOrFail();
        $this->assertGreaterThan(1, $shirt->variants()->where('is_active', true)->count(), 'clothing has size and colour variants with their own price and stock');
        $this->assertSame(['Size', 'Colour'], $shirt->options->pluck('name')->all());

        $this->assertSame(Product::query()->count(), DB::table('search_documents')->count(), 'the search index was built');
        $this->assertGreaterThan(0, DB::table('product_relations')->count(), 'recommendation links were built');

        // --- shipping methods addresses tracking and returns exist in every state
        foreach (Store::query()->get() as $store) {
            $types = $store->shippingMethods()->pluck('type')->map->value->all();
            $this->assertEqualsCanonicalizing(['flat', 'free_over', 'collection'], array_values(array_unique($types)), $store->name.' sells flat, free-over and collection');
        }

        $this->assertGreaterThanOrEqual(5, Address::query()->count());
        $this->assertTrue(Order::query()->whereNotNull('shipping_snapshot')->exists(), 'orders carry the address snapshot');

        $this->assertTrue(Shipment::query()->whereNotNull('tracking_number')->whereHas('events')->exists(), 'a shipment with the seller\'s tracking events');
        $this->assertSame(0, Shipment::query()->whereNull('seller_id')->count());

        $states = ReturnRequest::query()->distinct()->pluck('status')->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s)->all();
        foreach (['requested', 'approved', 'declined', 'refunded', 'escalated', 'closed'] as $state) {
            $this->assertContains($state, $states, "no return request in state {$state}");
        }

        $this->assertTrue(OrderItem::query()->where('status', 'refunded')->exists(), 'a returned and refunded line');
        $this->assertTrue(LedgerEntry::query()->where('status', 'held')->exists());
        $this->assertTrue(LedgerEntry::query()->where('status', 'released')->exists());
        $this->assertSame([], app(Reconciler::class)->run()['mismatches'], 'the ledger, payments and refunds reconcile');

        // --- reviews are verified by delivered lines and the was price follows the rules
        $this->assertSame(3, Review::query()->where('verified_purchase', false)->count(), 'only the legacy rows are unverified');
        $this->assertSame(0, Review::query()->where('verified_purchase', true)->whereNull('order_item_id')->count());

        $slim = Product::query()->where('name', 'like', 'Lenovo IdeaPad Slim 3%')->firstOrFail();
        $reference = ReferencePrice::for($slim);
        $this->assertNotNull($reference, 'a genuine drop after 30+ days of history shows a was price');
        $this->assertSame(999900, $reference['cents']);

        $this->assertNull(ReferencePrice::for(Product::query()->where('name', 'Mechanical Keyboard 75%')->firstOrFail()), 'a rise is not a was price');
        $this->assertNull(ReferencePrice::for(Product::query()->where('name', 'Wireless Mouse')->firstOrFail()), 'the earlier price was lower, so there is nothing to show');

        // --- the guest order link is printed and opens the order
        $order = Order::query()->where('guest_email', 'guest.shopper@emall-demo.test')->firstOrFail();
        $this->assertNull($order->user_id);

        $link = app(GuestOrderAccess::class)->url($order);
        $this->assertNotNull($link);

        // The link opens the order page for the browser (W5) and still answers a small JSON summary to a client that asks for JSON.
        $this->getJson(parse_url($link, PHP_URL_PATH))->assertStatus(200)->assertJsonFragment(['order' => $order->reference(), 'payment_status' => 'paid']);
        $this->get(parse_url($link, PHP_URL_PATH))->assertRedirect(route('guest.orders.show', $order));
        $this->get(route('guest.orders.show', $order))->assertOk()->assertSee($order->reference());

        // --- discovery data exists for people to find
        $this->assertGreaterThan(0, DB::table('wishlist_items')->count());
        $this->assertGreaterThan(0, DB::table('saved_searches')->count());
        $this->assertGreaterThan(0, DB::table('product_alerts')->count());
        $this->assertGreaterThan(0, DB::table('product_views')->count());
        $this->assertGreaterThan(0, DB::table('product_questions')->count());
        $this->assertGreaterThan(0, DB::table('collections')->where('visibility', 'public')->count());
        $this->assertGreaterThan(0, DB::table('commerce_events')->count());

        $this->assertNotEmpty(app(SearchLog::class)->popular(), 'popular searches exist (3+ different people, with results)');
        $this->assertNotEmpty(app(SearchLog::class)->zeroResults(), 'searches that found nothing are reported to the operator');

        $related = app(Recommender::class)->forProduct(Product::query()->where('name', 'like', 'Wireless Mouse')->firstOrFail(), Actor::none());
        $this->assertContains('bought_together', collect($related)->map(fn ($set) => $set->key)->all(), 'bought together has three real orders behind it');
    }

    public function test_it_is_idempotent_fresh_rebuilds_without_duplicates_and_purge_leaves_other_peoples_data_alone(): void
    {
        $other = User::factory()->create(['email' => 'real@example.com']);
        $otherStore = Store::factory()->create(['owner_id' => $other->id]);
        $otherProduct = Product::factory()->create(['store_id' => $otherStore->id, 'category_id' => null]);

        $baseline = collect(['orders', 'order_items', 'order_groups', 'shipments', 'shipment_events', 'return_requests', 'ledger_entries', 'payments', 'wishlist_items', 'saved_searches', 'product_alerts',
            'product_questions', 'product_answers', 'reviews', 'addresses', 'shipping_methods', 'collections', 'product_attributes', 'notifications', 'search_queries', 'product_views', 'commerce_events', 'promo_codes'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();

        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $counts = $this->counts();

        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $this->assertSame($counts, $this->counts(), 'a second run must change nothing');

        $this->artisan('emall:demo-seed --fresh')->assertExitCode(0);
        $this->assertSame($counts, $this->counts(), '--fresh must rebuild to the same shape, not add to it');
        $this->assertTrue(User::query()->whereKey($other->id)->exists());

        (new EmallDemoSeeder)->purge();

        // Compared with what was there before the seeder ran: on PostgreSQL the parallel audit tests commit rows of their own.
        foreach ($baseline as $table => $before) {
            $this->assertSame($before, DB::table($table)->count(), "{$table} has rows the seeder made after purge");
        }

        $this->assertSame(1, DB::table('product_variants')->count(), 'only the other shop\'s product keeps its variant');
        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Product::query()->whereKey($otherProduct->id)->exists());
        $this->assertSame(0, DB::table('brands')->count(), 'brands the demo introduced are removed');
        $this->assertGreaterThan(100, StoreCategory::query()->count(), 'the shared category tree stays');
    }

    public function test_the_dev_login_signs_a_demo_user_in_and_out_by_email_or_id(): void
    {
        $buyer = User::factory()->create(['email' => 'thandi@emall-demo.test']);

        $this->get('/dev/login/thandi@emall-demo.test?to=/orders')->assertRedirect('/orders');
        $this->assertAuthenticatedAs($buyer);

        $this->get('/dev/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->get('/dev/login/'.$buyer->id.'?to=//evil.example')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($buyer);
    }

    public function test_the_dev_routes_do_not_exist_in_production(): void
    {
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode([Illuminate\Support\Facades\Route::has("dev.login"), Illuminate\Support\Facades\Route::has("dev.logout")]);';
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 3), ['APP_ENV' => 'production']);
        $process->mustRun();

        $this->assertSame([false, false], json_decode($process->getOutput(), true));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'stores' => Store::query()->count(),
            'products' => Product::withTrashed()->count(),
            'orders' => Order::query()->count(),
            'reviews' => Review::query()->count(),
            'categories' => StoreCategory::query()->count(),
            'promos' => PromoCode::query()->count(),
            'notifications' => DB::table('notifications')->count(),
        ];
    }
}
