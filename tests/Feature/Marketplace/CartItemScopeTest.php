<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves HasUserScope itself is load-bearing on CartItem, independent of
 * any explicit where('user_id', ...) call or the abort_unless() guard
 * CartController::destroy() used to have (removed in the platform-loop
 * pass, 2026-08-04, once the scope made it redundant -- see
 * CartController's class docblock). Querying CartItem directly as
 * another user, with no manual scoping anywhere in the path, still
 * cannot see the row. This is the property that makes the scope
 * "defense in depth" rather than decorative -- it holds even if a
 * future controller or Livewire component forgets to scope a query
 * explicitly. Mirrors Dot.Finance's
 * test_scope_alone_blocks_cross_user_access_even_without_a_policy_check.
 */
class CartItemScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_user_access_even_without_a_policy_check(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $attacker = User::factory()->withPersonalTeam()->create();

        $store = Store::create([
            'owner_id' => User::factory()->create()->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);

        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 25,
            'stock' => 10,
            'is_active' => true,
        ]);

        $cartItem = CartItem::create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->actingAs($attacker);

        $this->assertNull(CartItem::find($cartItem->id));
        $this->assertSame(0, CartItem::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(CartItem::find($cartItem->id));
        $this->assertSame(1, CartItem::query()->count());
    }
}
