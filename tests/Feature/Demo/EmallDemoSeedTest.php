<?php

namespace Tests\Feature\Demo;

use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Review;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use Database\Seeders\EmallDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->artisan('emall:demo-seed')->assertExitCode(0);

        $this->assertSame(11, User::query()->where('email', 'like', '%@emall-demo.test')->count());
        $this->assertSame(4, Store::query()->count());
        $this->assertGreaterThanOrEqual(60, Product::query()->count());
        $this->assertGreaterThanOrEqual(13, Order::query()->count());
        $this->assertGreaterThan(0, Review::query()->count());
        $this->assertTrue(User::query()->where('email', 'operator@emall-demo.test')->value('is_platform_operator'));

        // Every order status and payment state the storefront can show is present.
        $this->assertEqualsCanonicalizing(
            ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
            Order::query()->distinct()->pluck('status')->map->value->all() ?: [],
        );
        foreach (['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_refunded'] as $state) {
            $this->assertTrue(Order::query()->where('payment_status', $state)->exists(), "no order with payment_status {$state}");
        }

        // Stock and rating aggregates are what the services leave behind, never negative.
        $this->assertSame(0, Product::query()->where('stock', '<', 0)->count());
        $this->assertTrue(Order::query()->whereHas('items')->exists());
        $multiSeller = Order::query()->withCount(['items as sellers' => fn ($q) => $q->select(\DB::raw('COUNT(DISTINCT seller_id)'))])->get()->max('sellers');
        $this->assertGreaterThanOrEqual(3, $multiSeller);
    }

    public function test_it_is_idempotent_and_fresh_rebuilds_without_duplicates(): void
    {
        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $counts = $this->counts();

        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $this->assertSame($counts, $this->counts(), 'a second run must change nothing');

        $this->artisan('emall:demo-seed --fresh')->assertExitCode(0);
        $this->assertSame($counts, $this->counts(), '--fresh must rebuild to the same shape, not add to it');
    }

    public function test_fresh_leaves_other_peoples_data_alone(): void
    {
        $other = User::factory()->create(['email' => 'real@example.com']);

        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $this->artisan('emall:demo-seed --fresh')->assertExitCode(0);

        $this->assertTrue(User::query()->whereKey($other->id)->exists());
    }

    public function test_the_dev_login_signs_a_demo_user_in_and_out_by_email_or_id(): void
    {
        $this->artisan('emall:demo-seed')->assertExitCode(0);
        $buyer = User::query()->where('email', 'thandi@emall-demo.test')->firstOrFail();

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
            'notifications' => \Illuminate\Support\Facades\DB::table('notifications')->count(),
        ];
    }
}
