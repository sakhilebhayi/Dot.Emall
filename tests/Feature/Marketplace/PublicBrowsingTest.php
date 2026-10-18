<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class PublicBrowsingTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_a_guest_can_browse_the_marketplace(): void
    {
        $seller = $this->makeSeller(['name' => 'Karoo Copperworks Trading']);
        $this->makeListing($seller, ['name' => 'Hand-hammered Copper Kettle']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Hand-hammered Copper Kettle')
            ->assertSee('Karoo Copperworks Trading');
    }

    public function test_a_guest_can_search_and_filter(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Searchable Brass Trivet']);
        $this->makeListing($seller, ['name' => 'Unrelated Ceramic Pitcher']);

        $this->get(route('products.index', ['q' => 'Trivet']))
            ->assertOk()
            ->assertSee('Searchable Brass Trivet')
            ->assertDontSee('Unrelated Ceramic Pitcher');
    }

    public function test_a_guest_can_open_a_product_page_and_is_invited_to_sign_in_to_buy(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Guest Visible Soapstone Bowl']);

        $page = $this->get(route('products.show', $product))->assertOk();

        $page->assertSee('Guest Visible Soapstone Bowl');
        $page->assertSee('Sign in to buy');
        $page->assertDontSee(route('cart.store'), false);
        $page->assertDontSee('Leave a review');
    }

    public function test_a_guest_product_visit_remembers_where_to_return_after_sign_in(): void
    {
        $product = $this->makeListing($this->makeSeller());

        $this->get(route('products.show', $product));

        $this->assertSame(route('products.show', $product), session('url.intended'));
    }

    public function test_guest_pages_expose_no_private_data(): void
    {
        $seller = $this->makeSeller();
        $seller->update(['name' => 'Ownerlastname Seller', 'email' => 'private-owner-address@example.test']);
        $product = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $buyer->update(['name' => 'Nomvula Zanele Dlamini', 'email' => 'private-buyer-address@example.test']);
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $order->items->first()->update(['status' => OrderStatus::Delivered]);
        Review::factory()->create(['product_id' => $product->id, 'user_id' => $buyer->id, 'body' => 'Lovely piece, packed beautifully.']);

        foreach ([route('products.index'), route('products.show', $product)] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertDontSee('private-owner-address@example.test');
            $page->assertDontSee('private-buyer-address@example.test');
            $page->assertDontSee('Ownerlastname');
            $page->assertDontSee('Dlamini');
            $page->assertDontSee('Protea Lane');
            $page->assertDontSee('+27 82 555 0142');
        }
    }

    public function test_guest_pages_do_not_render_the_signed_in_chrome(): void
    {
        $this->makeListing($this->makeSeller());

        $page = $this->get(route('products.index'))->assertOk();

        $page->assertDontSee('Profile &amp; Settings', false);
        $page->assertDontSee('Orders to fulfil');
        $page->assertSee('Sign in');
    }

    public function test_unbuyable_listings_stay_hidden_from_guests(): void
    {
        $seller = $this->makeSeller();
        $pending = $this->makeListing($seller, ['name' => 'Hidden Pending Listing One', 'status' => 'pending_approval']);
        $off = $this->makeListing($seller, ['name' => 'Hidden Inactive Listing Two', 'is_active' => false]);
        $closed = $this->makeSeller(['is_active' => false]);
        $inClosedStore = $this->makeListing($closed, ['name' => 'Hidden Closed Store Listing Three']);

        $this->get(route('products.index'))->assertDontSee('Hidden Pending Listing One')->assertDontSee('Hidden Inactive Listing Two')->assertDontSee('Hidden Closed Store Listing Three');
        foreach ([$pending, $off, $inClosedStore] as $product) {
            $this->get(route('products.show', $product))->assertNotFound();
        }
    }

    public function test_buying_and_everything_after_still_requires_sign_in(): void
    {
        $product = $this->makeListing($this->makeSeller());

        $this->get(route('cart.index'))->assertRedirect(route('login'));
        $this->post(route('cart.store'), ['product_id' => $product->id])->assertRedirect(route('login'));
        $this->post(route('cart.checkout'), [])->assertRedirect(route('login'));
        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->post(route('reviews.store', $product), ['rating' => 5])->assertRedirect(route('login'));
        $this->get(route('seller.products.index'))->assertRedirect(route('login'));
        $this->get(route('seller.orders.index'))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_signed_in_experience_is_unchanged(): void
    {
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller());

        $page = $this->actingAs($buyer)->get(route('products.show', $product))->assertOk();

        $page->assertSee(route('cart.store'), false);
        $page->assertDontSee('Sign in to buy');
    }
}
