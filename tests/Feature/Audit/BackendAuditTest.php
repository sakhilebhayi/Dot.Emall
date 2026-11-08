<?php

namespace Tests\Feature\Audit;

use App\Actions\Jetstream\DeleteUser;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Middleware\TrustedHostList;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\PersonalAccessToken;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\SellerDashboard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * Regression tests for the R-43 backend audit (docs/R43_BACKEND_AUDIT.md). Each test names the finding it pins.
 * Several of these passed on SQLite and failed on PostgreSQL before the fix: run them with phpunit.pgsql.xml too.
 */
class BackendAuditTest extends TestCase
{
    use BuildsMarketplace, RefreshDatabase;

    // ---- F-01: a stale stock number in the edit form must not put sold units back on the shelf ---------------------

    public function test_a_price_only_edit_does_not_resurrect_units_sold_while_the_form_was_open(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5, 'price' => '10.00']);

        $this->actingAs($seller)->get(route('seller.products.edit', $product))->assertOk();

        // A buyer takes three while the seller is looking at the form (which still says 5).
        $this->placeOrder($this->makeBuyer(), [[$product, 3]]);
        $this->assertSame(2, (int) $product->fresh()->stock);

        $this->actingAs($seller)
            ->patch(route('seller.products.update', $product), ['name' => $product->name, 'price' => '11.00', 'stock' => 5])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('11.00', $product->price);
        $this->assertSame(2, (int) $product->stock, 'the seller changed the price, not the stock: three units are still sold');
    }

    public function test_a_real_stock_change_is_applied_on_top_of_what_sold_meanwhile(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5, 'price' => '10.00']);

        $this->actingAs($seller)->get(route('seller.products.edit', $product));
        $this->placeOrder($this->makeBuyer(), [[$product, 3]]);

        // The seller restocked ten more: 5 shown, 15 typed. Two were left, so there are twelve.
        $response = $this->actingAs($seller)
            ->patch(route('seller.products.update', $product), ['name' => $product->name, 'price' => '10.00', 'stock' => 15]);

        $this->assertSame(12, (int) $product->fresh()->stock);
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Stock is 12'));
    }

    public function test_an_edit_with_no_remembered_form_uses_the_number_given(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);

        $this->actingAs($seller)->patch(route('seller.products.update', $product), ['name' => 'x', 'price' => '1.00', 'stock' => 9]);

        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    public function test_an_edit_never_takes_stock_below_zero(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);

        $this->actingAs($seller)->get(route('seller.products.edit', $product));
        $this->placeOrder($this->makeBuyer(), [[$product, 5]]);

        // The seller cut the stock from 5 to 1 (sold-out is "0"), but all five had already gone.
        $this->actingAs($seller)->patch(route('seller.products.update', $product), ['name' => 'x', 'price' => '1.00', 'stock' => 1]);

        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    // ---- F-02: numbers the database cannot hold were a 500 on PostgreSQL --------------------------------------------

    public function test_prices_and_stock_beyond_the_columns_are_validation_errors_not_server_errors(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->actingAs($seller);

        foreach ([
            ['price' => '10000000000000', 'stock' => 1],
            ['price' => '10000001', 'stock' => 1],
            ['price' => '10', 'stock' => 3000000000],
            ['price' => '10', 'stock' => 1, 'compare_price' => '99999999999999'],
            ['price' => '0.001', 'stock' => 1],
            ['price' => '10', 'stock' => 10000001],
        ] as $bad) {
            $this->patch(route('seller.products.update', $product), array_merge(['name' => 'x'], $bad))->assertSessionHasErrors();
        }

        $this->patch(route('seller.products.update', $product), ['name' => 'x', 'price' => '10000000', 'stock' => 10000000, 'compare_price' => '10000000.00'])
            ->assertSessionHasNoErrors();
    }

    public function test_promo_values_beyond_the_columns_are_validation_errors(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->post(route('seller.promo-codes.store'), ['code' => 'HUGE', 'discount_type' => 'fixed', 'discount_value' => '1000000000'])->assertSessionHasErrors('discount_value');
        $this->post(route('seller.promo-codes.store'), ['code' => 'FINE1', 'discount_type' => 'fixed', 'discount_value' => '0.005'])->assertSessionHasErrors('discount_value');
        $this->post(route('seller.promo-codes.store'), ['code' => 'USES', 'discount_type' => 'percent', 'discount_value' => '5', 'max_uses' => 5000000000])->assertSessionHasErrors('max_uses');
        $this->post(route('seller.promo-codes.store'), ['code' => 'OKAY', 'discount_type' => 'percent', 'discount_value' => '12.5', 'max_uses' => 100])->assertSessionHasNoErrors();
    }

    // ---- F-03: the storefront search was case sensitive on PostgreSQL ------------------------------------------------

    public function test_the_product_search_ignores_case(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Samsung Galaxy Phone']);
        $this->makeListing($seller, ['name' => 'Plain Shirt']);

        foreach (['samsung', 'SAMSUNG', 'galaxy PHONE', 'Samsung'] as $term) {
            $this->get('/products?q='.urlencode($term))->assertOk()->assertSee('Samsung Galaxy Phone')->assertDontSee('Plain Shirt');
        }
    }

    public function test_a_percent_or_underscore_in_the_search_is_matched_literally(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => '100% Cotton Shirt']);
        $this->makeListing($seller, ['name' => 'Plain Shirt']);
        $this->makeListing($seller, ['name' => 'Snake_case Mug']);
        $this->makeListing($seller, ['name' => 'Snakeacase Cup']);

        $this->get('/products?q='.urlencode('%'))->assertOk()->assertSee('100% Cotton Shirt')->assertDontSee('Plain Shirt');
        $this->get('/products?q='.urlencode('_'))->assertOk()->assertSee('Snake_case Mug')->assertDontSee('Plain Shirt');
        $this->get('/products?q='.urlencode('e_c'))->assertOk()->assertSee('Snake_case Mug')->assertDontSee('Snakeacase Cup');
        $this->get('/products?q='.urlencode('\\'))->assertOk()->assertDontSee('Plain Shirt');
    }

    public function test_category_counts_only_count_what_a_buyer_can_see(): void
    {
        $seller = $this->makeSeller();
        $category = StoreCategory::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $this->makeListing($seller, ['category_id' => $category->id]);
        $this->makeListing($seller, ['category_id' => $category->id, 'is_active' => false]);
        $this->makeListing($seller, ['category_id' => $category->id, 'status' => 'pending_approval']);

        // A category nobody can buy from (only unlisted products) is not offered as a filter at all.
        $empty = StoreCategory::create(['name' => 'Garden', 'slug' => 'garden']);
        $this->makeListing($seller, ['category_id' => $empty->id, 'is_active' => false]);

        $html = $this->get('/products')->assertOk()->getContent();

        // The facet row reads: <span>Kitchen</span> ... <span class="ds-facet__count"><span class="ds-visually-hidden">, </span>1</span>
        $this->assertMatchesRegularExpression('~<span>Kitchen</span>\s*<span class="ds-facet__count"><span class="ds-visually-hidden">, </span>1</span>~', $html);
        $this->assertStringNotContainsString('<span>Garden</span>', $html);
    }

    // ---- F-04: a made-up token with a "|" was a 500 on PostgreSQL ------------------------------------------------------

    public function test_a_token_whose_id_cannot_be_an_id_does_not_exist(): void
    {
        foreach (['|', 'x|y', '-1|abc', '1.5|abc', '99999999999999999999|abc', '|abc'] as $token) {
            $this->assertNull(PersonalAccessToken::findToken($token), $token);
        }

        $user = User::factory()->create();
        $real = $user->createToken('t')->plainTextToken;

        $this->assertNotNull(PersonalAccessToken::findToken($real));
    }

    public function test_the_api_and_the_sso_door_answer_cleanly_to_a_malformed_token(): void
    {
        $this->withHeader('Authorization', 'Bearer 99999999999999999999|abc')->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer |')->getJson('/api/user')->assertUnauthorized();

        config(['ecosystem.infodot.secret' => null]);
        $this->post(route('ecosystem.auth'), ['token' => '99999999999999999999|abc'], ['Origin' => 'https://infodot.co.za'])->assertRedirect('/login');
        $this->assertGuest();
    }

    // ---- F-05: an overlap lock must not outlive a killed run ------------------------------------------------------------

    public function test_every_scheduled_task_releases_its_overlap_lock_within_the_hour(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, $event->command.' should not overlap itself');
            $this->assertLessThanOrEqual(60, $event->expiresAt, $event->command.' holds its lock for '.$event->expiresAt.' minutes: a killed run would block the stock-releasing sweep for a day');
        }
    }

    // ---- F-06: deleting an account took paid orders and their stock with it -----------------------------------------------

    public function test_a_buyer_with_an_order_on_its_way_cannot_delete_the_account(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 2]]);
        $order->forceFill(['requires_payment' => true, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        try {
            app(DeleteUser::class)->delete($buyer);
            $this->fail('the account was deleted with a paid order still open');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertNotNull($buyer->fresh());
        $this->assertSame(1, $order->fresh()->items()->count());
    }

    public function test_a_buyer_whose_orders_are_finished_can_delete_the_account(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $order->items()->update(['status' => OrderStatus::Delivered->value]);

        app(DeleteUser::class)->delete($buyer);

        $this->assertNull($buyer->fresh());
    }

    public function test_a_seller_with_a_paid_line_to_ship_cannot_delete_the_account(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);

        // Not paid, but nothing requires payment: the line is the seller's to ship.
        $this->assertTrue($order->isFulfillable());

        $this->expectException(ValidationException::class);
        app(DeleteUser::class)->delete($seller);
    }

    public function test_a_seller_with_nothing_open_is_deleted_and_the_listing_photos_go_with_them(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        Storage::disk('public')->put('product-images/'.$product->id.'/a.jpg', 'x');
        $product->images()->create(['path' => 'product-images/'.$product->id.'/a.jpg', 'sort_order' => 0]);
        CartItem::withoutGlobalScopes()->create(['user_id' => $this->makeBuyer()->id, 'product_id' => $product->id, 'quantity' => 1]);

        app(DeleteUser::class)->delete($seller);

        $this->assertNull($seller->fresh());
        Storage::disk('public')->assertMissing('product-images/'.$product->id.'/a.jpg');
        $this->assertSame(0, CartItem::withoutGlobalScopes()->count());
    }

    // ---- F-07: the seller dashboard adds revenue up in SQL --------------------------------------------------------------

    public function test_the_seller_revenue_matches_the_line_by_line_sum_across_currencies_and_promo_shares(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => '19.99', 'stock' => 50]);
        $b = $this->makeListing($seller, ['price' => '7.35', 'stock' => 50]);
        $buyer = $this->makeBuyer();
        $first = $this->placeOrder($buyer, [[$a, 3], [$b, 7]]);
        $second = $this->placeOrder($buyer, [[$a, 1]]);
        $second->forceFill(['currency' => 'USD'])->save();

        OrderItem::query()->update(['status' => OrderStatus::Delivered->value, 'discount_amount' => '1.37']);
        $second->items()->first()->forceFill(['status' => OrderStatus::Cancelled])->save();

        $summary = app(SellerDashboard::class)->summary($seller);

        $expectedZar = OrderItem::where('order_id', $first->id)->get()->sum(fn ($l) => $l->netCents());
        $this->assertSame($expectedZar, $summary['revenueByCurrency']['ZAR']);
        $this->assertSame(0, $summary['revenueByCurrency']['USD'] ?? 0, 'a cancelled line earns nothing');
    }

    // ---- F-08: the indexes the pages and cascades walk ------------------------------------------------------------------

    public function test_the_foreign_key_indexes_exist(): void
    {
        foreach ([
            'product_images' => 'product_images_product_id_sort_order_index',
            'products' => 'products_category_id_index',
            'order_items' => 'order_items_store_id_index',
            'reviews' => 'reviews_user_id_index',
            'cart_items' => 'cart_items_product_id_index',
            'refunds' => 'refunds_payment_id_index',
            'payment_events' => 'payment_events_order_id_index',
        ] as $table => $index) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$table} is missing {$index}");
        }
    }

    // ---- F-09: a forged Host header was copied into the password-reset link and the Paystack callback ----------------------

    public function test_the_trusted_host_list_is_the_app_url_host_unless_told_otherwise(): void
    {
        config(['app.url' => 'https://emall.example.co.za', 'security.trusted_hosts' => []]);
        $this->assertSame(['^emall\.example\.co\.za$'], TrustedHostList::patterns(Request::create('/login')));

        config(['security.trusted_hosts' => ['emall.example.co.za', 'EMALL.example.app']]);
        $this->assertSame(['^emall\.example\.co\.za$', '^emall\.example\.app$'], TrustedHostList::patterns(Request::create('/login')));
    }

    public function test_the_health_probe_and_an_unconfigured_app_are_never_locked_out(): void
    {
        config(['app.url' => 'https://emall.example.co.za', 'security.trusted_hosts' => []]);
        $this->assertSame([], TrustedHostList::patterns(Request::create('/up')));

        config(['app.url' => 'http://localhost']);
        $this->assertSame([], TrustedHostList::patterns(Request::create('/login')));
    }

    // ---- F-10: a double click on "Add to cart" was a 500 ------------------------------------------------------------------

    public function test_adding_the_same_product_twice_at_once_adds_on_top_instead_of_failing(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 10]);
        $buyer = $this->makeBuyer();

        // The competing request lands between this one's read of the cart and its write.
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced, $buyer, $product) {
            if (! $raced && str_starts_with(strtolower($query), 'insert') && str_contains($query, 'cart_items')) {
                $raced = true;
                DB::table('cart_items')->insert(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $product->defaultVariant->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertRedirect(route('cart.index'));

        $this->assertTrue($raced, 'the competing insert really happened first');
        $this->assertSame(3, (int) CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->sum('quantity'));
        $this->assertSame(1, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count());
    }

    // ---- Upload safety (no defect found): an image that is also a script keeps neither the script nor a script name -------

    public function test_a_jpeg_with_php_appended_is_stored_without_the_php_and_with_a_random_image_name(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $image = imagecreatetruecolor(300, 300);
        ob_start();
        imagejpeg($image);
        $polyglot = ob_get_clean().'<?php system($_GET["c"]); ?>';

        $this->actingAs($seller)
            ->post(route('seller.products.images.store', $product), ['images' => [UploadedFile::fake()->createWithContent('shell.php.jpg', $polyglot)]])
            ->assertSessionHasNoErrors();

        $path = $product->images()->firstOrFail()->path;
        $stored = Storage::disk('public')->get($path);

        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('system(', $stored);
        $this->assertMatchesRegularExpression('#^product-images/\d+/[A-Za-z0-9]{40}\.(jpg|png|webp)$#', $path);
    }
}
