<?php

namespace Tests\Feature\Marketplace;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BecomeSellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('seller.store.create'))->assertRedirect('/login');
    }

    public function test_submitting_the_form_creates_an_active_store(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seller.store.store'), [
                'name' => 'My New Shop',
                'description' => 'Selling handmade goods.',
            ])
            ->assertRedirect();

        $store = Store::where('owner_id', $user->id)->first();
        $this->assertNotNull($store);
        $this->assertSame('My New Shop', $store->name);
        $this->assertTrue($store->is_active);
    }

    public function test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_id' => $user->id,
            'name' => 'Existing Shop',
            'slug' => 'existing-shop',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('seller.store.create'))
            ->assertRedirect(route('seller.products.create'));

        $this->actingAs($user)->post(route('seller.store.store'), [
            'name' => 'Second Shop',
        ]);

        $this->assertSame(1, Store::where('owner_id', $user->id)->count());
    }
}
