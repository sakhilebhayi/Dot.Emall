<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromoCodeTest extends TestCase
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

    // ─── Seller management ──────────────────────────────────────────────

    public function test_a_seller_can_create_a_promo_code_for_their_store(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $this->makeStore($seller);

        $this->actingAs($seller)
            ->post(route('seller.promo-codes.store'), [
                'code' => 'welcome10',
                'discount_type' => 'percent',
                'discount_value' => 10,
            ])
            ->assertRedirect(route('seller.promo-codes.index'));

        $this->assertDatabaseHas('promo_codes', ['code' => 'WELCOME10', 'discount_type' => 'percent']);
    }

    public function test_a_percent_discount_over_100_is_rejected(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $this->makeStore($seller);

        $this->actingAs($seller)
            ->post(route('seller.promo-codes.store'), [
                'code' => 'TOOMUCH', 'discount_type' => 'percent', 'discount_value' => 150,
            ])
            ->assertSessionHasErrors('discount_value');

        $this->assertDatabaseCount('promo_codes', 0);
    }

    public function test_a_seller_can_delete_their_own_promo_code(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $promo = PromoCode::create([
            'store_id' => $store->id, 'code' => 'BYE', 'discount_type' => 'fixed', 'discount_value' => 5,
        ]);

        $this->actingAs($seller)
            ->delete(route('seller.promo-codes.destroy', $promo))
            ->assertRedirect(route('seller.promo-codes.index'));

        $this->assertDatabaseMissing('promo_codes', ['id' => $promo->id]);
    }

    public function test_a_seller_cannot_delete_another_sellers_promo_code(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $otherStore = $this->makeStore();
        $promo = PromoCode::create([
            'store_id' => $otherStore->id, 'code' => 'NOTYOURS', 'discount_type' => 'fixed', 'discount_value' => 5,
        ]);

        $this->actingAs($seller)
            ->delete(route('seller.promo-codes.destroy', $promo))
            ->assertForbidden();

        $this->assertDatabaseHas('promo_codes', ['id' => $promo->id]);
    }

    // ─── PromoCode model logic ──────────────────────────────────────────

    public function test_percent_discount_is_computed_correctly(): void
    {
        $promo = PromoCode::create([
            'store_id' => $this->makeStore()->id, 'code' => 'X', 'discount_type' => 'percent', 'discount_value' => 20,
        ]);

        $this->assertSame(20.0, $promo->discountFor(100));
    }

    public function test_fixed_discount_is_capped_at_the_eligible_subtotal(): void
    {
        $promo = PromoCode::create([
            'store_id' => $this->makeStore()->id, 'code' => 'X', 'discount_type' => 'fixed', 'discount_value' => 50,
        ]);

        $this->assertSame(30.0, $promo->discountFor(30));
    }

    public function test_an_expired_code_is_not_redeemable(): void
    {
        $promo = PromoCode::create([
            'store_id' => $this->makeStore()->id, 'code' => 'X', 'discount_type' => 'fixed', 'discount_value' => 5,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($promo->isRedeemable());
    }

    public function test_a_code_at_its_usage_cap_is_not_redeemable(): void
    {
        $promo = PromoCode::create([
            'store_id' => $this->makeStore()->id, 'code' => 'X', 'discount_type' => 'fixed', 'discount_value' => 5,
            'max_uses' => 2, 'used_count' => 2,
        ]);

        $this->assertFalse($promo->isRedeemable());
    }

    // ─── Checkout integration ───────────────────────────────────────────

    public function test_checkout_applies_a_valid_promo_code(): void
    {
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['price' => 100]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        PromoCode::create([
            'store_id' => $store->id, 'code' => 'SAVE10', 'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St', 'promo_code' => 'save10',
        ]);

        $order = Order::where('user_id', $buyer->id)->firstOrFail();
        $this->assertEquals(100, (float) $order->subtotal);
        $this->assertEquals(10, (float) $order->discount_amount);
        $this->assertEquals(90, (float) $order->total);
        $this->assertSame('SAVE10', $order->promo_code);
    }

    public function test_checkout_increments_the_promo_codes_used_count(): void
    {
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['price' => 100]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        $promo = PromoCode::create([
            'store_id' => $store->id, 'code' => 'SAVE10', 'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St', 'promo_code' => 'SAVE10',
        ]);

        $this->assertSame(1, $promo->fresh()->used_count);
    }

    public function test_checkout_rejects_an_unknown_promo_code(): void
    {
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['price' => 100]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $response = $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St', 'promo_code' => 'DOESNOTEXIST',
        ]);

        $response->assertRedirect(route('cart.index'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_a_promo_code_from_a_store_not_in_the_cart(): void
    {
        $cartStore = $this->makeStore();
        $product = $this->makeProduct($cartStore, ['price' => 100]);
        $otherStore = $this->makeStore();
        PromoCode::create([
            'store_id' => $otherStore->id, 'code' => 'NOTAPPLICABLE', 'discount_type' => 'fixed', 'discount_value' => 10,
        ]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $response = $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St', 'promo_code' => 'NOTAPPLICABLE',
        ]);

        $response->assertRedirect(route('cart.index'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_promo_code_only_discounts_its_own_stores_items_in_a_mixed_seller_cart(): void
    {
        $storeA = $this->makeStore();
        $storeB = $this->makeStore();
        $productA = $this->makeProduct($storeA, ['price' => 100]);
        $productB = $this->makeProduct($storeB, ['price' => 50]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $productA->id, 'quantity' => 1]);
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $productB->id, 'quantity' => 1]);
        // 20% off, but only Store A's items -- eligible subtotal is 100, not 150.
        PromoCode::create([
            'store_id' => $storeA->id, 'code' => 'STOREA20', 'discount_type' => 'percent', 'discount_value' => 20,
        ]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St', 'promo_code' => 'STOREA20',
        ]);

        $order = Order::where('user_id', $buyer->id)->firstOrFail();
        $this->assertEquals(150, (float) $order->subtotal);
        $this->assertEquals(20, (float) $order->discount_amount); // 20% of 100, not of 150
        $this->assertEquals(130, (float) $order->total);
    }

    public function test_checkout_without_a_promo_code_has_zero_discount(): void
    {
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['price' => 100]);
        $buyer = User::factory()->withPersonalTeam()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer)->post(route('cart.checkout'), ['checkout_token' => (string) Str::uuid(), 'shipping_address' => '1 Main St']);

        $order = Order::where('user_id', $buyer->id)->firstOrFail();
        $this->assertEquals(0, (float) $order->discount_amount);
        $this->assertNull($order->promo_code);
        $this->assertEquals(100, (float) $order->total);
    }
}
