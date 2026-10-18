<?php

namespace Tests\Feature\Marketplace;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_a_seller_can_open_the_edit_page_with_their_current_details(): void
    {
        $seller = $this->makeSeller(['name' => 'Karoo Copperworks Trading', 'description' => 'Beaten copper from the Karoo.']);

        $this->actingAs($seller)->get(route('seller.store.edit'))
            ->assertOk()
            ->assertSee('Karoo Copperworks Trading')
            ->assertSee('Beaten copper from the Karoo.');
    }

    public function test_a_seller_can_rename_and_describe_their_store(): void
    {
        $seller = $this->makeSeller(['name' => 'Old Name Trading']);
        $slug = $seller->store->slug;

        $this->actingAs($seller)->patch(route('seller.store.update'), [
            'name' => 'Brand New Store Name',
            'description' => 'Fresh description.',
            'is_active' => '1',
        ])->assertRedirect(route('seller.store.edit'))->assertSessionHasNoErrors();

        $store = $seller->store()->first();
        $this->assertSame('Brand New Store Name', $store->name);
        $this->assertSame('Fresh description.', $store->description);
        $this->assertSame($slug, $store->slug, 'the public slug does not change under people\'s feet');
    }

    public function test_a_seller_can_close_and_reopen_the_store_and_listings_follow(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Follows The Store Open Closed']);

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => $seller->store->name]);
        $this->assertFalse($seller->store()->first()->is_active);
        $this->get(route('products.index'))->assertDontSee('Follows The Store Open Closed');

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => $seller->store->name, 'is_active' => '1']);
        $this->assertTrue($seller->store()->first()->is_active);
        $this->get(route('products.index'))->assertSee('Follows The Store Open Closed');
    }

    public function test_the_name_is_required_and_bounded(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');
    }

    public function test_someone_without_a_store_is_sent_to_open_one(): void
    {
        $user = $this->makeBuyer();

        $this->actingAs($user)->get(route('seller.store.edit'))->assertRedirect(route('seller.store.create'));
        $this->actingAs($user)->patch(route('seller.store.update'), ['name' => 'x'])->assertRedirect(route('seller.store.create'));
    }

    public function test_a_seller_only_ever_edits_their_own_store(): void
    {
        $a = $this->makeSeller(['name' => 'Alpha Store Untouched']);
        $b = $this->makeSeller(['name' => 'Bravo Store Original']);

        $this->actingAs($b)->patch(route('seller.store.update'), ['name' => 'Bravo Store Renamed', 'is_active' => '1']);

        $this->assertSame('Alpha Store Untouched', $a->store()->first()->name);
        $this->assertSame('Bravo Store Renamed', $b->store()->first()->name);
    }

    public function test_renaming_the_store_leaves_placed_orders_as_they_were(): void
    {
        $seller = $this->makeSeller(['name' => 'Name At Time Of Sale']);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => 'Name After The Rename', 'is_active' => '1']);

        $this->assertSame('Name At Time Of Sale', $order->items()->first()->store_name);
    }

    public function test_the_database_allows_one_store_per_owner(): void
    {
        $seller = $this->makeSeller();

        $this->expectException(UniqueConstraintViolationException::class);

        Store::factory()->create(['owner_id' => $seller->id]);
    }

    public function test_opening_a_second_store_through_the_form_is_refused_politely(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('seller.store.store'), ['name' => 'Second Store Attempt'])
            ->assertRedirect(route('seller.products.create'));

        $this->assertSame(1, Store::where('owner_id', $seller->id)->count());
    }

    public function test_the_stores_relation_is_the_one_store_and_a_user_without_one_has_none(): void
    {
        $seller = $this->makeSeller(['name' => 'The One Store']);

        $this->assertSame('The One Store', $seller->store->name);
        $this->assertNull(User::factory()->create()->store);
    }

    public function test_the_edit_page_is_linked_from_the_sidebar_and_dashboard(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->get(route('dashboard'))->assertSee(route('seller.store.edit'), false);
    }
}
