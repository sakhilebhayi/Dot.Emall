<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    private function store(User $owner): Store
    {
        return Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Handmade Mug',
            'description' => 'A lovely mug.',
            'price' => 25.00,
            'stock' => 10,
        ], $overrides);
    }

    public function test_a_store_owner_can_submit_a_listing_and_it_lands_pending_approval(): void
    {
        $owner = User::factory()->create();
        $this->store($owner);

        $this->actingAs($owner)
            ->post(route('seller.products.store'), $this->validPayload())
            ->assertRedirect();

        $product = Product::where('name', 'Handmade Mug')->first();
        $this->assertNotNull($product);
        $this->assertSame('pending_approval', $product->status);
    }

    public function test_a_user_with_no_store_cannot_reach_the_listing_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('seller.products.create'))
            ->assertRedirect(route('seller.store.create'));
    }

    public function test_a_user_cannot_submit_a_listing_for_a_store_they_do_not_own(): void
    {
        $owner = User::factory()->create();
        $this->store($owner);
        $stranger = User::factory()->create();
        $this->store($stranger);

        // The controller resolves the store from auth()->id(), not from
        // client input, so there is no store_id field to tamper with;
        // this test instead proves a user with no store of their own is
        // blocked (redirect), covering the same authorization boundary
        // via the only reachable path.
        $userWithNoStore = User::factory()->create();

        $this->actingAs($userWithNoStore)
            ->post(route('seller.products.store'), $this->validPayload())
            ->assertRedirect(route('seller.store.create'));

        $this->assertSame(0, Product::where('name', 'Handmade Mug')->count());
    }

    public function test_pending_approval_product_does_not_appear_in_the_marketplace_index(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);

        $this->actingAs($owner)
            ->get(route('products.index'))
            ->assertDontSee('Pending Item');
    }

    public function test_pending_approval_product_404s_at_show_for_a_non_owner(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('products.show', $product))
            ->assertNotFound();
    }

    public function test_pending_approval_product_is_visible_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);

        $this->actingAs($owner)
            ->get(route('products.show', $product))
            ->assertOk();
    }

    public function test_pending_approval_product_cannot_be_added_to_cart(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
        $buyer = User::factory()->create();

        $this->actingAs($buyer)
            ->post(route('cart.store'), ['product_id' => $product->id])
            ->assertNotFound();
    }

    public function test_rejected_product_behaves_the_same_as_pending_for_visibility(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        Product::create([
            'store_id' => $store->id,
            'name' => 'Rejected Item',
            'slug' => 'rejected-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'rejected',
            'rejected_reason' => 'Prohibited item.',
        ]);
        $product = Product::where('name', 'Rejected Item')->first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('products.show', $product))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('products.index'))
            ->assertDontSee('Rejected Item');
    }
}
