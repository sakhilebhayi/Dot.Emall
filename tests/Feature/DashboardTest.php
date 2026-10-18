<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Seller desk');
    }

    public function test_dashboard_shows_review_and_low_stock_widgets_for_the_sellers_own_products(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = Store::create([
            'owner_id' => $seller->id,
            'name' => 'My Store',
            'slug' => 'my-store',
            'is_active' => true,
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Low Stock Widget',
            'slug' => 'low-stock-widget',
            'price' => 15,
            'stock' => 2,
            'is_active' => true,
        ]);
        $reviewer = User::factory()->create();
        Review::create(['product_id' => $product->id, 'user_id' => $reviewer->id, 'rating' => 5]);

        $response = $this->actingAs($seller)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('1 listing low on stock.');
        $response->assertSee('5.0'); // average rating from the single 5-star review
    }

    public function test_dashboard_lists_the_sellers_recent_orders(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $buyer = User::factory()->create();
        $store = Store::create([
            'owner_id' => $seller->id,
            'name' => 'My Store',
            'slug' => 'my-store-2',
            'is_active' => true,
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Sold Widget',
            'slug' => 'sold-widget',
            'price' => 30,
            'stock' => 10,
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'subtotal' => 30,
            'total' => 30,
        ]);
        OrderItem::factory()->forProduct($product)->status(OrderStatus::Delivered)->create(['order_id' => $order->id]);

        $response = $this->actingAs($seller)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('$30.00');
        $response->assertSee($order->reference());
    }
}
