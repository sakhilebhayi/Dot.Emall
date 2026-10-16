<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sellers could create a listing (SellerProductController::store()) but
 * never edit or deactivate one afterward -- this covers the missing
 * edit/update/toggle-active actions.
 */
class SellerListingManagementTest extends TestCase
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

    public function test_a_seller_can_edit_their_own_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $product = $this->makeProduct($store, ['price' => 20, 'stock' => 5]);

        $this->actingAs($seller)
            ->patch(route('seller.products.update', $product), [
                'name' => 'Updated Widget',
                'price' => 30,
                'stock' => 12,
            ])
            ->assertRedirect(route('seller.products.index'));

        $product->refresh();
        $this->assertSame('Updated Widget', $product->name);
        $this->assertEquals(30, (float) $product->price);
        $this->assertSame(12, $product->stock);
    }

    public function test_editing_does_not_reset_an_approved_listings_moderation_status(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $product = $this->makeProduct($store, ['status' => 'approved']);

        $this->actingAs($seller)->patch(route('seller.products.update', $product), [
            'name' => 'Renamed', 'price' => 25, 'stock' => 10,
        ]);

        $this->assertSame('approved', $product->fresh()->status);
    }

    public function test_a_seller_cannot_edit_another_sellers_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $otherStore = $this->makeStore();
        $product = $this->makeProduct($otherStore, ['name' => 'Not Yours']);

        $this->actingAs($seller)
            ->patch(route('seller.products.update', $product), ['name' => 'Hijacked', 'price' => 1, 'stock' => 1])
            ->assertForbidden();

        $this->assertSame('Not Yours', $product->fresh()->name);
    }

    public function test_a_seller_can_deactivate_and_reactivate_their_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $product = $this->makeProduct($store, ['is_active' => true]);

        $this->actingAs($seller)->patch(route('seller.products.toggle-active', $product));
        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($seller)->patch(route('seller.products.toggle-active', $product));
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_deactivated_listing_is_no_longer_browsable(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $product = $this->makeProduct($store, ['is_active' => true]);

        $this->actingAs($seller)->patch(route('seller.products.toggle-active', $product));

        $this->assertSame(0, Product::browsable()->where('id', $product->id)->count());
    }

    public function test_a_seller_cannot_deactivate_another_sellers_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $otherStore = $this->makeStore();
        $product = $this->makeProduct($otherStore, ['is_active' => true]);

        $this->actingAs($seller)
            ->patch(route('seller.products.toggle-active', $product))
            ->assertForbidden();

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_a_seller_sees_only_their_own_listings_in_the_index(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore($seller);
        $mine = $this->makeProduct($store, ['name' => 'Mine']);
        $otherStore = $this->makeStore();
        $this->makeProduct($otherStore, ['name' => 'Not Mine']);

        $this->actingAs($seller)
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Not Mine');
    }
}
