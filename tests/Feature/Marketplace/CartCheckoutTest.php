<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the checkout flow added in the platform-loop pass: before this,
 * CartItem existed but nothing ever turned a cart into an Order. These
 * tests exercise the full add-to-cart -> checkout -> stock-decrement ->
 * seller-notification path against the real schema.
 */
class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(?User $owner = null): Store
    {
        $owner ??= User::factory()->create();

        return Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeProduct(Store $store, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 25,
            'stock' => 10,
            'is_active' => true,
            'status' => 'approved',
        ], $overrides));
    }

    public function test_authenticated_user_can_add_a_product_to_their_cart(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore());

        $this->actingAs($buyer)
            ->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_adding_the_same_product_twice_increments_quantity_instead_of_duplicating(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore());

        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 3]);
    }

    /**
     * CartItem now applies HasUserScope (platform-loop pass, 2026-08-04):
     * implicit route-model binding on {cartItem} queries through that
     * scope, so another user's cart item is invisible before the
     * controller body ever runs -- this now 404s rather than 403ing,
     * the same fail-closed-at-the-query-layer shift documented on
     * Dot.Finance's HasUserScope and FinanceAuthorizationTest.
     */
    public function test_a_user_cannot_remove_another_users_cart_item(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore());
        $cartItem = CartItem::create(['user_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($intruder)
            ->delete(route('cart.destroy', $cartItem))
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    public function test_checkout_creates_an_order_decrements_stock_and_clears_the_cart(): void
    {
        $seller = User::factory()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($store, ['stock' => 5, 'price' => 40]);

        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 3]);

        $response = $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '123 Main St',
        ]);

        $order = Order::where('user_id', $buyer->id)->first();
        $response->assertRedirect(route('orders.show', $order));

        $this->assertNotNull($order);
        $this->assertEquals(120, (float) $order->total);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertEquals(2, $product->fresh()->stock);

        // Seller gets a database notification about the new order.
        $this->assertEquals(1, $seller->fresh()->unreadNotifications()->count());
    }

    public function test_checkout_dispatches_order_placed_for_the_created_order(): void
    {
        Event::fake([OrderPlaced::class]);

        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore(), ['stock' => 5, 'price' => 10]);

        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '123 Main St',
        ]);

        $order = Order::where('user_id', $buyer->id)->first();

        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->order->is($order));
    }

    public function test_checkout_does_not_dispatch_order_placed_when_stock_is_insufficient(): void
    {
        Event::fake([OrderPlaced::class]);

        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore(), ['stock' => 1]);

        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '123 Main St',
        ]);

        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_checkout_is_rejected_when_stock_is_insufficient(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore(), ['stock' => 1]);

        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 5]);

        $response = $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '123 Main St',
        ]);

        $response->assertRedirect(route('cart.index'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_checkout_requires_a_shipping_address(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $product = $this->makeProduct($this->makeStore());
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer)
            ->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid()])
            ->assertSessionHasErrors('shipping_address');

        $this->assertDatabaseCount('orders', 0);
    }
}
