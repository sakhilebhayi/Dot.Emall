<?php

namespace Tests\Feature\Account;

use App\Actions\Jetstream\DeleteUser;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Address;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SavedSearch;
use App\Models\Store;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Account\AccountEraser;
use App\Services\Ledger\LedgerService;
use App\Services\Returns\ReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * D-7 and D-98: closing an account anonymises the person and keeps the order and money records. Refused while an order, a
 * return, a refund or held earnings are open.
 */
class AccountErasureTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closeAccount(User $user): void
    {
        app(DeleteUser::class)->delete($user);
    }

    private function assertRefused(User $user, string $reasonFragment): void
    {
        try {
            $this->closeAccount($user);
            $this->fail('the account was closed while something was open');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($reasonFragment, $e->errors()['password'][0]);
        }

        $fresh = $user->fresh();
        $this->assertSame($user->email, $fresh->email, 'a refused request changes nothing');
        $this->assertNotSame(User::FORMER_NAME, $fresh->name);
    }

    /** A paid, delivered order of one R100 line, with the seller's earnings held in the ledger. */
    private function deliveredPaidOrder(): array
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 40]);
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 5]);
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $this->payOrder($order);
        $order = $this->deliver($order);

        return [$seller, $buyer, $order, $product];
    }

    public function test_a_buyer_with_a_delivered_order_is_anonymised_and_every_record_of_the_sale_stays(): void
    {
        Storage::fake('public');
        [$seller, $buyer, $order, $product] = $this->deliveredPaidOrder();
        $beforeEmail = $buyer->email;
        $order->refresh();
        $snapshot = $order->shipping_snapshot;
        $total = $order->total;

        Address::factory()->create(['user_id' => $buyer->id]);
        Wishlist::factory()->create(['user_id' => $buyer->id]);
        SavedSearch::factory()->create(['user_id' => $buyer->id]);
        ProductAlert::factory()->create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        $buyer->createToken('phone');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $buyer->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $review = Review::factory()->create(['product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 4, 'body' => 'Good kettle.']);
        $payments = Payment::query()->where('order_id', $order->id)->count();
        $ledger = LedgerEntry::query()->where('order_id', $order->id)->count();
        $this->assertGreaterThan(0, $payments);
        $this->assertGreaterThan(0, $ledger);

        $this->closeAccount($buyer);

        $gone = $buyer->fresh();
        $this->assertNotNull($gone, 'the row stays: the orders still point at it');
        $this->assertSame('Former customer', $gone->name);
        $this->assertNotSame($beforeEmail, $gone->email);
        $this->assertStringEndsWith('@deleted.invalid', $gone->email);
        $this->assertNull($gone->profile_photo_path);
        $this->assertNull(User::query()->where('email', $beforeEmail)->first(), 'signing in with the old address finds nobody');

        // The person's own private rows are gone.
        $this->assertSame(0, Address::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, Wishlist::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, SavedSearch::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, ProductAlert::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, $gone->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $buyer->id)->count());

        // The sale is still there, in full, with the delivery details given at the time.
        $order = Order::query()->findOrFail($order->id);
        $this->assertSame($buyer->id, $order->user_id);
        $this->assertSame($total, $order->total);
        $this->assertSame($snapshot, $order->shipping_snapshot);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($payments, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame($ledger, LedgerEntry::query()->where('order_id', $order->id)->count());

        // The review stays and is shown under the former name.
        $this->assertSame('Good kettle.', $review->fresh()->body);
        $this->assertSame('Former customer', $review->fresh()->user->publicName());
    }

    public function test_a_buyer_with_an_unfinished_order_cannot_close_the_account(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $unpaid = $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 5]), 1]]);

        $this->assertRefused($buyer, 'is waiting for payment');
        $this->assertStringContainsString($unpaid->reference(), $this->reasons($buyer)[0]);

        $this->payOrder($unpaid);
        $this->assertRefused($buyer, 'has not reached you yet');

        // Finished means delivered: now it can go.
        $this->deliver($unpaid);
        $this->closeAccount($buyer);
        $this->assertSame('Former customer', $buyer->fresh()->name);
    }

    public function test_a_cancelled_order_does_not_hold_the_account(): void
    {
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller(), ['stock' => 5]), 1]]);
        $order->items()->update(['status' => OrderStatus::Cancelled->value]);
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->assertSame([], $this->reasons($buyer));
        $this->closeAccount($buyer);
        $this->assertSame(1, Order::query()->where('user_id', $buyer->id)->count());
    }

    public function test_an_open_return_and_a_refund_not_yet_paid_back_hold_the_account(): void
    {
        [, $buyer, $order] = $this->deliveredPaidOrder();

        $return = app(ReturnService::class)->request($order->items()->first(), $buyer, 'faulty', 'broken');
        $this->assertRefused($buyer, 'is still open');

        $return->forceFill(['status' => 'closed'])->save();
        $this->assertSame([], $this->reasons($buyer));

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        Refund::query()->create(['payment_id' => $payment->id, 'order_id' => $order->id, 'amount_cents' => 1000, 'currency' => 'ZAR', 'status' => RefundStatus::Pending->value, 'kind' => 'return']);
        $this->assertRefused($buyer, 'has not been paid back yet');
    }

    public function test_a_seller_with_open_parcels_cannot_close_the_account(): void
    {
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller, ['stock' => 5]), 1]]);

        $this->assertRefused($seller, 'still has parcels open');
        $this->assertStringContainsString($order->reference(), $this->reasons($seller)[0]);
        $this->assertNotNull(Store::query()->where('owner_id', $seller->id)->first());
    }

    public function test_a_seller_with_earnings_still_held_must_wait_and_one_with_released_earnings_may_go(): void
    {
        [$seller, , $order] = $this->deliveredPaidOrder();
        Storage::fake('public');
        $store = $seller->store;
        $store->forceFill(['logo_path' => 'store-media/'.$store->id.'/logo-x.png', 'contact_email' => 'shop@example.test'])->save();
        Storage::disk('public')->put($store->logo_path, 'x');

        $this->assertGreaterThan(0, $this->held($seller));
        $this->assertRefused($seller, 'earnings held in the ledger');

        // Seven days after delivery the earnings are released and the seller is no longer held.
        Carbon::setTestNow(now()->addDays(8));
        app(LedgerService::class)->releaseDue();
        $this->assertSame(0, $this->held($seller));
        $this->assertGreaterThan(0, app(LedgerService::class)->balance($seller)['ZAR']['released']);

        $entries = LedgerEntry::query()->where('seller_id', $seller->id)->count();
        $this->closeAccount($seller);

        $gone = $seller->fresh();
        $this->assertSame('Former customer', $gone->name);
        $store = Store::query()->where('owner_id', $seller->id)->firstOrFail();
        $this->assertFalse($store->is_active, 'the store is switched off, not deleted');
        $this->assertNull($store->logo_path);
        $this->assertNull($store->contact_email);
        Storage::disk('public')->assertMissing('store-media/'.$store->id.'/logo-x.png');
        $this->assertSame($entries, LedgerEntry::query()->where('seller_id', $seller->id)->count(), 'the ledger is untouched');
        $this->assertSame($seller->id, OrderItem::query()->where('order_id', $order->id)->value('seller_id'));
        $this->assertSame(0, Product::query()->where('store_id', $store->id)->count(), 'listings are archived');
        $this->assertGreaterThan(0, Product::withTrashed()->where('store_id', $store->id)->count());
    }

    public function test_guest_orders_are_not_touched_by_anyone_closing_an_account(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $guest = Order::factory()->create(['user_id' => null, 'guest_email' => 'guest@example.test', 'status' => OrderStatus::Delivered]);
        $total = $guest->total;
        $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 5]), 1]])->items()->update(['status' => OrderStatus::Delivered->value]);

        $this->closeAccount($buyer);

        $guest = $guest->fresh();
        $this->assertNull($guest->user_id);
        $this->assertSame('guest@example.test', $guest->guest_email);
        $this->assertSame($total, $guest->total);
    }

    public function test_the_database_itself_refuses_to_delete_a_user_who_owns_a_sale(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 5]), 1]]);

        foreach ([$buyer, $seller] as $user) {
            try {
                // A savepoint, so PostgreSQL's aborted-transaction state does not outlive the refused statement.
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete());
                $this->fail('a user with orders or a store was deleted by hand');
            } catch (QueryException) {
                $this->assertNotNull($user->fresh());
            }
        }
    }

    public function test_every_money_key_to_users_restricts_and_the_personal_ones_still_cascade(): void
    {
        $restricted = ['orders' => 'user_id', 'stores' => 'owner_id', 'order_items' => 'seller_id', 'order_groups' => 'seller_id', 'ledger_entries' => 'seller_id',
            'return_requests' => 'requester_id', 'reviews' => 'user_id', 'product_questions' => 'user_id'];

        foreach ($restricted as $table => $column) {
            // PostgreSQL reports RESTRICT as such; SQLite reports the same rule as "restrict" too, but "no action" is the other safe spelling.
            $this->assertContains($this->action($table, $column), ['restrict', 'no action'], "{$table}.{$column} must restrict");
        }

        foreach (['addresses' => 'user_id', 'wishlists' => 'user_id', 'cart_items' => 'user_id'] as $table => $column) {
            $this->assertSame('cascade', $this->action($table, $column), "{$table}.{$column} stays a private row");
        }
    }

    public function test_the_migration_is_safe_to_run_twice_and_reversible_and_keeps_indexes_and_rows(): void
    {
        $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller(), ['stock' => 5]), 1]]);
        $indexes = fn () => collect(Schema::getIndexes('orders'))->pluck('name')->sort()->values()->all();
        $before = $indexes();
        $rows = Order::query()->count();

        $migration = require database_path('migrations/2026_11_11_000001_restrict_user_foreign_keys_on_money_records.php');
        $migration->up();
        $this->assertSame($before, $indexes());

        $migration->down();
        $this->assertSame('cascade', $this->action('orders', 'user_id'));
        $this->assertSame('set null', $this->action('order_items', 'seller_id'));
        $this->assertSame($before, $indexes());

        $migration->up();
        $this->assertSame('restrict', $this->action('orders', 'user_id'));
        $this->assertSame($rows, Order::query()->count());
        $this->assertSame($before, $indexes());
    }

    public function test_the_privacy_page_lists_every_reason_and_otherwise_explains_what_stays(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->get(route('account.privacy'))->assertOk()->assertSee('Your past orders, returns and payment records stay')->assertSee('Former customer')->assertDontSee('You cannot delete your account yet');

        $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 5]), 1]]);
        $this->actingAs($buyer)->get(route('account.privacy'))->assertSee('You cannot delete your account yet')->assertSee('has not reached you yet');
    }

    /** @return array<int, string> */
    private function reasons(User $user): array
    {
        return app(AccountEraser::class)->obstacles($user->fresh());
    }

    private function held(User $seller): int
    {
        return (int) (app(LedgerService::class)->balance($seller)['ZAR']['held'] ?? 0);
    }

    private function action(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return strtolower((string) $key['on_delete']);
            }
        }

        return null;
    }
}
