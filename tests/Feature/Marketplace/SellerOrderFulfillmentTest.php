<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sellers previously had no way to see or fulfill orders at all (wiki.md
 * §5: "no route or controller changes [order status] after creation
 * today"). These tests cover SellerOrderController and the per-item
 * status ladder it enforces.
 */
class SellerOrderFulfillmentTest extends TestCase
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

    private function makeOrderWithItem(Store $store, User $buyer, array $itemOverrides = []): array
    {
        $product = $this->makeProduct($store);
        $order = Order::create([
            'user_id' => $buyer->id,
            'status' => 'pending',
            'subtotal' => 25,
            'total' => 25,
            'shipping_address' => '1 Main St',
        ]);
        $item = OrderItem::factory()->forProduct($product)->create(array_merge(['order_id' => $order->id], $itemOverrides));

        return [$order, $item];
    }

    public function test_a_seller_sees_orders_containing_their_products(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->create();
        [$order] = $this->makeOrderWithItem($store, $buyer);

        $this->actingAs($seller)
            ->get(route('seller.orders.index'))
            ->assertOk()
            ->assertSee($order->reference());
    }

    public function test_a_seller_does_not_see_orders_with_none_of_their_products(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $this->makeStore($seller);
        $otherStore = $this->makeStore();
        $buyer = User::factory()->create();
        [$order] = $this->makeOrderWithItem($otherStore, $buyer);

        $this->actingAs($seller)
            ->get(route('seller.orders.index'))
            ->assertOk()
            ->assertDontSee($order->reference());
    }

    public function test_a_seller_can_advance_their_own_order_items_status(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->create();
        [$order, $item] = $this->makeOrderWithItem($store, $buyer);

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $item), ['status' => 'processing'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Processing, $item->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    public function test_a_seller_cannot_update_another_sellers_order_item(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $intruderStore = $this->makeStore();
        $store = $this->makeStore();
        $buyer = User::factory()->create();
        [, $item] = $this->makeOrderWithItem($store, $buyer);

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $item), ['status' => 'processing'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $item->fresh()->status);
    }

    public function test_status_cannot_be_moved_backward(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->create();
        [, $item] = $this->makeOrderWithItem($store, $buyer, ['status' => OrderStatus::Shipped]);

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $item), ['status' => 'processing'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Shipped, $item->fresh()->status);
    }

    public function test_a_delivered_item_cannot_be_changed_again(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->create();
        [, $item] = $this->makeOrderWithItem($store, $buyer, ['status' => OrderStatus::Delivered]);

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $item), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Delivered, $item->fresh()->status);
    }

    public function test_order_status_only_becomes_delivered_once_every_item_is_delivered(): void
    {
        $sellerA = User::factory()->withPersonalTeam()->create();
        $sellerB = User::factory()->withPersonalTeam()->create();
        $storeA = $this->makeStore($sellerA);
        $storeB = $this->makeStore($sellerB);
        $buyer = User::factory()->create();

        $productA = $this->makeProduct($storeA);
        $productB = $this->makeProduct($storeB);
        $order = Order::create([
            'user_id' => $buyer->id, 'status' => 'pending', 'subtotal' => 50, 'total' => 50, 'shipping_address' => '1 Main St',
        ]);
        $itemA = OrderItem::factory()->forProduct($productA)->create(['order_id' => $order->id]);
        $itemB = OrderItem::factory()->forProduct($productB)->create(['order_id' => $order->id]);

        $this->actingAs($sellerA)->patch(route('seller.order-items.update-status', $itemA), ['status' => 'delivered']);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        $this->actingAs($sellerB)->patch(route('seller.order-items.update-status', $itemB), ['status' => 'delivered']);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_buyer_receives_a_notification_when_their_item_status_changes(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $buyer = User::factory()->withPersonalTeam()->create();
        [, $item] = $this->makeOrderWithItem($store, $buyer);

        $this->actingAs($seller)->patch(route('seller.order-items.update-status', $item), ['status' => 'shipped']);

        $this->assertSame(1, $buyer->fresh()->unreadNotifications()->count());
    }

    public function test_a_seller_can_open_a_shared_order_but_only_manage_their_own_items(): void
    {
        $sellerA = User::factory()->withPersonalTeam()->create();
        $storeA = $this->makeStore($sellerA);
        $sellerB = User::factory()->withPersonalTeam()->create();
        $storeB = $this->makeStore($sellerB);
        $buyer = User::factory()->create();

        $productA = $this->makeProduct($storeA);
        $productB = $this->makeProduct($storeB);
        $order = Order::create([
            'user_id' => $buyer->id, 'status' => 'pending', 'subtotal' => 50, 'total' => 50, 'shipping_address' => '1 Main St',
        ]);
        OrderItem::factory()->forProduct($productA)->create(['order_id' => $order->id]);
        $itemB = OrderItem::factory()->forProduct($productB)->create(['order_id' => $order->id]);

        // Seller A can open the order (OrderPolicy::view()) but sees only their own line ...
        $this->actingAs($sellerA)->get(route('orders.show', $order))->assertOk()->assertViewHas('view', fn ($view) => $view['lines']->pluck('seller_id')->all() === [$sellerA->id]);

        // ... but cannot update Seller B's item within it.
        $this->actingAs($sellerA)
            ->patch(route('seller.order-items.update-status', $itemB), ['status' => 'processing'])
            ->assertForbidden();
    }
}
