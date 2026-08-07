<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(array $overrides = []): Store
    {
        $owner = User::factory()->create();

        return Store::create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    private function makeProduct(Store $store, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 19.99,
            'stock' => 10,
            'is_active' => true,
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/products')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_browse_products(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $this->makeProduct($store, ['name' => 'Vintage Lamp']);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertViewIs('products.index')
            ->assertSee('Vintage Lamp');
    }

    public function test_inactive_products_are_excluded_from_the_browse_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $this->makeProduct($store, ['name' => 'Hidden Item', 'is_active' => false]);

        $response = $this->actingAs($user)->get(route('products.index'));

        $response->assertOk();
        $response->assertDontSee('Hidden Item');
    }

    public function test_products_under_an_inactive_store_are_excluded(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore(['is_active' => false]);
        $this->makeProduct($store, ['name' => 'Orphaned Item']);

        $response = $this->actingAs($user)->get(route('products.index'));

        $response->assertOk();
        $response->assertDontSee('Orphaned Item');
    }

    public function test_search_filters_by_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $this->makeProduct($store, ['name' => 'Unique Alpha Chair']);
        $this->makeProduct($store, ['name' => 'Totally Different Beta']);

        $response = $this->actingAs($user)->get(route('products.index', ['q' => 'Alpha']));

        $response->assertOk();
        $response->assertSee('Unique Alpha Chair');
        $response->assertDontSee('Totally Different Beta');
    }

    public function test_category_filter_scopes_results(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $electronics = StoreCategory::create(['name' => 'Electronics', 'slug' => 'electronics']);
        $furniture = StoreCategory::create(['name' => 'Furniture', 'slug' => 'furniture']);

        $this->makeProduct($store, ['name' => 'Laptop Stand', 'category_id' => $electronics->id]);
        $this->makeProduct($store, ['name' => 'Wooden Sofa', 'category_id' => $furniture->id]);

        $response = $this->actingAs($user)->get(route('products.index', ['category' => $electronics->id]));

        $response->assertOk();
        $response->assertSee('Laptop Stand');
        $response->assertDontSee('Wooden Sofa');
    }

    public function test_category_filter_rejects_a_category_id_that_does_not_exist(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get(route('products.index', ['category' => 999999]));

        $response->assertSessionHasErrors('category');
    }

    public function test_product_detail_page_shows_the_product(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['name' => 'Ceramic Mug', 'description' => 'A fine mug.']);

        $this->actingAs($user)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertViewIs('products.show')
            ->assertSee('Ceramic Mug')
            ->assertSee('A fine mug.');
    }

    public function test_inactive_product_404s_for_a_non_owner(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = $this->makeStore();
        $product = $this->makeProduct($store, ['is_active' => false]);

        $this->actingAs($user)
            ->get(route('products.show', $product))
            ->assertNotFound();
    }
}
