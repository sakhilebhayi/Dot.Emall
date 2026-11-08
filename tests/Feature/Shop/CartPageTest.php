<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Models\PromoCode;
use App\Models\ShippingMethod;
use App\Models\Wishlist;
use App\Services\Cart\CartManager;
use App\Support\Shell;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

class CartPageTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsShopFlows;
    use RefreshDatabase;

    public function test_a_guest_adds_to_the_session_cart_and_sees_it_grouped_by_seller_with_the_total_to_pay(): void
    {
        $a = $this->makeSeller(['name' => 'Karoo Homeware']);
        $b = $this->makeSeller(['name' => 'Veld Outdoors']);
        $this->withDelivery($a, ['price' => 50]);
        $this->withDelivery($b, ['price' => 80]);
        $kettle = $this->makeListing($a, ['name' => 'Copper Kettle Guest Test', 'price' => 200, 'stock' => 9]);
        $tent = $this->makeListing($b, ['name' => 'Two Person Tent Guest Test', 'price' => 900, 'stock' => 9]);

        $this->post(route('cart.store'), ['product_id' => $kettle->id, 'quantity' => 2])->assertRedirect(route('cart.index'));
        $this->post(route('cart.store'), ['product_id' => $tent->id])->assertRedirect(route('cart.index'));

        $page = $this->get(route('cart.index'))->assertOk();
        $page->assertSee('Sold and sent by Karoo Homeware')->assertSee('Sold and sent by Veld Outdoors')
            ->assertSee('Copper Kettle Guest Test')->assertSee('Two Person Tent Guest Test')
            ->assertSee('Parcel 1 of 2')->assertSee('Total to pay')
            // 2 x 200 + 50 delivery, 900 + 80 delivery = 1,430.00, and the label says how it is made up
            ->assertSee('R1,430.00')->assertSee('Delivery, Karoo Homeware')->assertSee('Estimated delivery', false)
            ->assertSee('Includes VAT where the seller charges it');
        $this->assertSame(3, app(CartManager::class)->count(null));
    }

    public function test_the_header_cart_count_is_bound_for_guests_and_accounts(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 9]);
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3]);

        $this->assertSame(3, Shell::cartCount());

        $buyer = $this->makeBuyer();
        $this->addToCart($buyer, $product, 2);
        $this->actingAs($buyer);
        $this->assertSame(2, Shell::cartCount());
    }

    public function test_quantity_changes_obey_stock_with_a_clear_message_and_a_remove_works(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Stock Limited Mug', 'stock' => 3, 'price' => 40]);
        $variant = $product->defaultVariant()->first();
        $this->addToCart(null, $product);

        $this->patch(route('cart.update', $variant->id), ['quantity' => 3])->assertRedirect(route('cart.index'))->assertSessionHasNoErrors();
        $this->assertSame(3, app(CartManager::class)->count(null));

        $this->patch(route('cart.update', $variant->id), ['quantity' => 4])->assertSessionHasErrors('quantity');
        $this->followingRedirects()->patch(route('cart.update', $variant->id), ['quantity' => 4])->assertSee('Only 3 of');
        $this->assertSame(3, app(CartManager::class)->count(null));

        $this->delete(route('cart.destroy', $variant->id))->assertRedirect(route('cart.index'));
        $this->assertSame(0, app(CartManager::class)->count(null));
    }

    public function test_one_guest_cannot_touch_a_line_that_is_not_in_their_cart(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $variant = $product->defaultVariant()->first();

        $this->patch(route('cart.update', $variant->id), ['quantity' => 2])->assertNotFound();
        $this->delete(route('cart.destroy', $variant->id))->assertNotFound();
    }

    public function test_an_unavailable_line_is_explained_and_checkout_is_not_offered(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Switched Off Lamp', 'stock' => 5]);
        $this->addToCart(null, $product, 2);
        $product->update(['is_active' => false]);

        $this->get(route('cart.index'))->assertOk()
            ->assertSee('No longer available. Remove it to continue.')
            ->assertSee('Fix the items marked above before you check out')
            ->assertDontSee('href="'.route('checkout.show').'"', false);

        $this->get(route('checkout.show'))->assertRedirect(); // a guest with only a dead line is not sent into checkout
    }

    public function test_a_price_change_is_announced_with_both_prices_and_never_applied_silently(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Shifting Price Kettle', 'price' => 100, 'stock' => 9]);
        $this->addToCart(null, $product);
        $this->get(route('cart.index'))->assertOk()->assertDontSee('Some prices changed');

        $product->defaultVariant()->first()->update(['price' => 120]);

        $page = $this->get(route('cart.index'))->assertSee('Some prices changed since you last looked')->assertSee('was R100.00, now R120.00 each');
        $page->assertSee('R120.00');

        $this->post(route('cart.prices.accept'))->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertDontSee('Some prices changed');
    }

    public function test_a_promo_code_is_checked_on_the_server_with_its_terms_and_a_bad_code_changes_nothing(): void
    {
        $seller = $this->makeSeller(['name' => 'Promo Stores']);
        $product = $this->makeListing($seller, ['price' => 200, 'stock' => 9]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TENOFF', 'discount_type' => 'percent', 'discount_value' => 10, 'expires_at' => now()->addDays(3), 'max_uses' => 5]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'OLDONE', 'discount_type' => 'percent', 'discount_value' => 50, 'expires_at' => now()->subDay()]);
        $this->addToCart(null, $product);

        $this->post(route('cart.promo'), ['promo_code' => 'NOPE'])->assertSessionHasErrors('promo_code');
        $this->post(route('cart.promo'), ['promo_code' => 'OLDONE'])->assertSessionHasErrors('promo_code');
        $this->get(route('cart.index'))->assertDontSee('is applied');

        $this->post(route('cart.promo'), ['promo_code' => 'tenoff'])->assertRedirect(route('cart.index'))->assertSessionHasNoErrors();
        $page = $this->get(route('cart.index'))->assertSee('TENOFF')->assertSee('10% off, on items from Promo Stores only')->assertSee('Valid until')->assertSee('5 uses in total')
            ->assertSee('R180.00');
        $this->assertSame(0, PromoCode::where('code', 'TENOFF')->value('used_count'), 'previewing a code never spends a use');

        $this->delete(route('cart.promo.clear'))->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertDontSee('is applied')->assertSee('R200.00');
    }

    public function test_the_sellers_promo_conditions_note_is_printed_where_the_code_applies(): void
    {
        $seller = $this->makeSeller(['name' => 'Terms Stores']);
        $this->withDelivery($seller);
        $product = $this->makeListing($seller, ['price' => 200, 'stock' => 9]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TERMS10', 'discount_type' => 'percent', 'discount_value' => 10, 'conditions' => 'One per household, not with other offers']);
        $this->addToCart(null, $product);

        $this->post(route('cart.promo'), ['promo_code' => 'TERMS10'])->assertSessionHasNoErrors();

        $this->get(route('cart.index'))->assertSee("The seller's terms: One per household, not with other offers.", false);
        $this->get(route('checkout.show'))->assertSee("Seller's terms: One per household, not with other offers", false);
    }

    public function test_delivery_prices_follow_the_place_and_the_chosen_method(): void
    {
        $seller = $this->makeSeller(['name' => 'Two Method Stores']);
        $this->withDelivery($seller, ['name' => 'Standard courier', 'price' => 60]);
        $this->withDelivery($seller, ['name' => 'Express courier', 'price' => 150, 'window_min_days' => 1, 'window_max_days' => 1]);
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 9]);
        $this->addToCart(null, $product);

        $methods = $seller->store->shippingMethods()->orderBy('price')->get();
        $this->get(route('cart.index'))->assertSee('Standard courier')->assertSee('Express courier')->assertSee('R160.00');

        $this->post(route('cart.delivery'), ['shipping_methods' => [$seller->store->id => $methods[1]->id]])->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertSee('R250.00');

        // a method id that is not this store's is ignored, not trusted
        $this->post(route('cart.delivery'), ['shipping_methods' => [$seller->store->id => 999999]]);
        $this->get(route('cart.index'))->assertSee('R250.00');

        $this->post(route('cart.region'), ['province' => 'Gauteng', 'postal_code' => '2193'])->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertSee('Gauteng, 2193');
        $this->post(route('cart.region'), ['province' => 'Narnia'])->assertSessionHasErrors('province');
    }

    public function test_free_delivery_progress_is_stated_with_the_real_remainder(): void
    {
        $seller = $this->makeSeller();
        ShippingMethod::factory()->freeOver(500, 70)->create(['store_id' => $seller->store->id, 'name' => 'Free over R500']);
        $this->addToCart(null, $this->makeListing($seller, ['price' => 300, 'stock' => 9]));

        $this->get(route('cart.index'))->assertSee('Spend R200.00 more with this seller for free delivery');
    }

    public function test_the_empty_cart_offers_a_way_on_and_only_real_suggestions(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Newest Listing Suggestion', 'stock' => 4]);

        $this->get(route('cart.index'))->assertOk()
            ->assertSee('Your cart is empty')->assertSee('Continue shopping')->assertSee('Sign in with InfoDot')
            ->assertSee('New on Emall')->assertSee('Newest Listing Suggestion');
    }

    public function test_a_signed_in_buyer_moves_a_line_to_the_wishlist_and_a_guest_is_pointed_to_sign_in(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Wished For Kettle', 'stock' => 9]);
        $variant = $product->defaultVariant()->first();
        $buyer = $this->makeBuyer();
        $this->addToCart($buyer, $product);

        $this->actingAs($buyer)->get(route('cart.index'))->assertSee('Move to wishlist');
        $this->actingAs($buyer)->post(route('cart.wishlist', $variant->id))->assertRedirect(route('cart.index'));

        $this->assertSame(0, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count());
        $this->assertSame(1, Wishlist::query()->where('user_id', $buyer->id)->first()->items()->count());

    }

    public function test_a_guest_is_pointed_to_sign_in_to_save_for_later(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 9]);
        $this->addToCart(null, $product);

        $this->get(route('cart.index'))->assertSee('Sign in with InfoDot to save for later')->assertDontSee('Move to wishlist');
        $this->post(route('cart.wishlist', $product->defaultVariant()->first()->id))->assertRedirect(route('login'));
    }

    public function test_the_guest_cart_merges_into_the_account_cart_at_sign_in(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 9]);
        $this->addToCart(null, $product, 2);
        $buyer = $this->makeBuyer();

        event(new Login('web', $buyer, false));

        $this->assertSame(2, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->sum('quantity'));
        $this->assertSame(0, app(CartManager::class)->count(null));
    }

    public function test_collection_is_never_the_silent_default_when_the_seller_also_delivers(): void
    {
        $seller = $this->makeSeller(['name' => 'Collect Or Deliver']);
        ShippingMethod::factory()->collection()->create(['store_id' => $seller->store->id]);
        $this->withDelivery($seller, ['name' => 'Courier to your door', 'price' => 75]);
        $this->addToCart(null, $this->makeListing($seller, ['price' => 100, 'stock' => 5]));

        // Collection is cheaper (free), yet the starting choice and the total are the delivery option; collection is one press away.
        $this->get(route('cart.index'))->assertSee('R175.00')->assertSee('Courier to your door')->assertSee('Collect from us');
        $this->post(route('cart.delivery'), ['shipping_methods' => [$seller->store->id => $seller->store->shippingMethods()->where('type', 'collection')->value('id')]]);
        $this->get(route('cart.index'))->assertSee('R100.00');
    }
}
