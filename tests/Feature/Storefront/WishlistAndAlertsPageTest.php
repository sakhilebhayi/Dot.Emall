<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductAlert;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Cart\CartManager;
use App\Services\Catalogue\ProductVariants;
use App\Services\Discovery\AlertEvaluator;
use App\Services\Discovery\ProductAlerts;
use App\Services\Discovery\Wishlists;
use App\Support\WishState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

class WishlistAndAlertsPageTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    public function test_the_wishlist_is_for_signed_in_people_and_starts_empty(): void
    {
        $this->get(route('wishlist.index'))->assertRedirect(route('login'));
        $this->actingAs($this->makeBuyer())->get(route('wishlist.index'))->assertOk()->assertSee('Your wishlist is empty')->assertSee('Sharing is off');
    }

    public function test_the_heart_saves_and_unsaves_with_a_plain_form_post(): void
    {
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Heart Kettle Distinct', 'stock' => 5]);

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, WishlistItem::count());
        $this->actingAs($buyer)->get(route('wishlist.index'))->assertSee('Heart Kettle Distinct');

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product))->assertRedirect();
        $this->assertSame(0, WishlistItem::count());
    }

    public function test_the_heart_answers_json_for_the_script_and_a_guest_gets_a_sign_in_link_instead(): void
    {
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);

        $this->actingAs($buyer)->postJson(route('wishlist.toggle', $product))->assertOk()->assertJson(['saved' => true]);
        $this->actingAs($buyer)->postJson(route('wishlist.toggle', $product))->assertOk()->assertJson(['saved' => false]);

        $this->app['auth']->forgetGuards();
        $html = $this->get(route('products.show', $product))->getContent();
        $this->assertStringContainsString('Sign in to save', $html);
        $this->assertStringNotContainsString('data-wish-toggle', $html);
        $this->postJson(route('wishlist.toggle', $product))->assertRedirect(route('login'));
    }

    public function test_the_heart_state_is_drawn_on_the_product_page_and_a_product_off_sale_cannot_be_saved(): void
    {
        $buyer = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        app(Wishlists::class)->add($buyer, $product);

        $this->actingAs($buyer)->get(route('products.show', $product))->assertSee('Saved to wishlist')->assertSee('aria-pressed="true"', false);

        $off = $this->makeListing($this->makeSeller(), ['is_active' => false]);
        $this->actingAs($buyer)->postJson(route('wishlist.toggle', $off))->assertStatus(422);
    }

    public function test_saving_a_chosen_variant_remembers_it_and_toggling_off_removes_whatever_variant_was_saved(): void
    {
        [, $product] = $this->shirt();
        $buyer = $this->makeBuyer();
        $variant = $product->variants()->where('stock', 5)->first();

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product), ['variant_id' => $variant->id]);
        $this->assertSame($variant->id, WishlistItem::firstOrFail()->variant_id);

        $this->actingAs($buyer)->get(route('wishlist.index'))->assertSee('Size: M, Colour: Red')->assertSee('variant='.$variant->id, false);

        $this->actingAs($buyer)->post(route('wishlist.toggle', $product));
        $this->assertSame(0, WishlistItem::count());
    }

    public function test_a_variant_of_another_product_is_ignored_when_saving(): void
    {
        [, $product] = $this->shirt();
        $other = $this->makeListing($this->makeSeller(), ['stock' => 3]);

        $this->actingAs($this->makeBuyer())->post(route('wishlist.toggle', $product), ['variant_id' => $other->defaultVariant()->first()->id]);

        $this->assertNull(WishlistItem::firstOrFail()->variant_id);
    }

    public function test_move_to_cart_works_and_sends_a_product_that_needs_a_choice_to_its_page(): void
    {
        $buyer = $this->makeBuyer();
        $plain = $this->makeListing($this->makeSeller(), ['name' => 'Plain Moveable', 'stock' => 4]);
        [, $shirt] = $this->shirt();
        $lists = app(Wishlists::class);
        $a = $lists->add($buyer, $plain);
        $b = $lists->add($buyer, $shirt);

        $this->actingAs($buyer)->post(route('wishlist.items.cart', $a))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, app(CartManager::class)->count($buyer));
        $this->assertSame(1, WishlistItem::count(), 'a moved item leaves the list');

        $this->actingAs($buyer)->post(route('wishlist.items.cart', $b))->assertRedirect(route('products.show', ['product' => $shirt->id, 'src' => 'wishlist']))->assertSessionHas('error');
        $this->assertSame(1, WishlistItem::count());
    }

    public function test_nobody_else_can_touch_your_wishlist_items(): void
    {
        $owner = $this->makeBuyer();
        $item = app(Wishlists::class)->add($owner, $this->makeListing($this->makeSeller(), ['stock' => 3]));
        $thief = $this->makeBuyer();

        $this->actingAs($thief)->delete(route('wishlist.items.destroy', $item))->assertForbidden();
        $this->actingAs($thief)->post(route('wishlist.items.cart', $item))->assertForbidden();
        $this->actingAs($thief)->post(route('wishlist.items.alert', $item), ['kind' => 'price_drop', 'on' => 1])->assertForbidden();
        $this->assertSame(1, WishlistItem::count());
        $this->actingAs($thief)->get(route('wishlist.index'))->assertDontSee($item->product->name);
    }

    public function test_sharing_is_off_by_default_gives_an_unguessable_link_and_the_old_link_dies_when_it_is_switched_off(): void
    {
        $owner = $this->makeBuyer();
        $visible = $this->makeListing($this->makeSeller(), ['name' => 'Shared Visible Thing', 'stock' => 3]);
        $gone = $this->makeListing($this->makeSeller(), ['name' => 'Shared Gone Thing', 'stock' => 3]);
        $lists = app(Wishlists::class);
        $lists->add($owner, $visible, null, 'my private note about this');
        $lists->add($owner, $gone);
        $gone->update(['is_active' => false]);

        $this->assertNull(Wishlist::firstOrFail()->share_token);

        $this->actingAs($owner)->post(route('wishlist.sharing'), ['share' => 1])->assertRedirect();
        $token = Wishlist::firstOrFail()->share_token;
        $this->assertSame(40, strlen($token));

        $this->app['auth']->forgetGuards();
        $shared = $this->get(route('wishlist.shared', $token))->assertOk()->assertSee('Shared Visible Thing')->assertSee('noindex', false)->getContent();
        $this->assertStringNotContainsString('Shared Gone Thing', $shared, 'a product that can no longer be bought is not shown');
        $this->assertStringNotContainsString('my private note', $shared);
        $this->assertStringNotContainsString($owner->name, $shared);
        $this->assertStringNotContainsString($owner->email, $shared);

        $this->actingAs($owner)->post(route('wishlist.sharing'), ['share' => 0]);
        $this->app['auth']->forgetGuards();
        $this->get(route('wishlist.shared', $token))->assertNotFound();
        $this->get(route('wishlist.shared', str_repeat('a', 40)))->assertNotFound();
        $this->get('/wishlist/shared/short')->assertNotFound();
    }

    public function test_the_owner_page_shows_the_share_link_and_the_price_they_saved_it_at(): void
    {
        $owner = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Priced Item Distinct', 'price' => 100, 'stock' => 3]);
        app(Wishlists::class)->add($owner, $product);
        $product->update(['price' => 80]);
        $this->actingAs($owner)->post(route('wishlist.sharing'), ['share' => 1]);

        $this->actingAs($owner)->get(route('wishlist.index'))->assertSee('/wishlist/shared/', false)->assertSee('You saved it at R100.00')->assertSee('It is cheaper now')->assertSee('Turn sharing off');
    }

    public function test_per_item_alerts_toggle_on_and_off(): void
    {
        $owner = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        $item = app(Wishlists::class)->add($owner, $product);

        $this->actingAs($owner)->post(route('wishlist.items.alert', $item), ['kind' => 'price_drop', 'on' => 1])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, ProductAlert::where('active', true)->count());
        $this->actingAs($owner)->get(route('wishlist.index'))->assertSee('Price alert on');

        $this->actingAs($owner)->post(route('wishlist.items.alert', $item), ['kind' => 'price_drop', 'on' => 0])->assertRedirect();
        $this->assertSame(0, ProductAlert::where('active', true)->count());

        $this->actingAs($owner)->post(route('wishlist.items.alert', $item), ['kind' => 'back_in_stock', 'on' => 1])->assertSessionHas('error');
        $this->actingAs($owner)->post(route('wishlist.items.alert', $item), ['kind' => 'nonsense', 'on' => 1])->assertSessionHasErrors('kind');
    }

    public function test_an_out_of_stock_wishlist_item_offers_a_stock_alert_and_no_move_to_cart(): void
    {
        $owner = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Out Of Stock Wish', 'stock' => 0]);
        $item = app(Wishlists::class)->add($owner, $product);

        $this->actingAs($owner)->get(route('wishlist.index'))->assertSee('Out of stock')->assertSee('Tell me when it is back')->assertDontSee('Move to cart');
        $this->actingAs($owner)->post(route('wishlist.items.alert', $item), ['kind' => 'back_in_stock', 'on' => 1])->assertSessionHas('status');
        $this->assertSame(1, ProductAlert::where('kind', 'back_in_stock')->count());
    }

    public function test_a_back_in_stock_alert_from_the_product_page_waits_for_that_variant_only(): void
    {
        [, $product] = $this->shirt();
        $buyer = $this->makeBuyer();
        $soldOut = $product->variants()->where('stock', 0)->firstOrFail();

        $this->actingAs($buyer)->post(route('alerts.store', $product), ['kind' => 'back_in_stock', 'variant_id' => $soldOut->id])->assertRedirect()->assertSessionHas('status');
        $alert = ProductAlert::firstOrFail();
        $this->assertSame($soldOut->id, $alert->variant_id);

        // Another size has stock, so the product is "in stock": the alert must NOT fire for that.
        $this->assertSame(0, app(AlertEvaluator::class)->backInStock());
        $this->assertTrue($alert->fresh()->active);

        app(ProductVariants::class)->saveVariant($product, ['Size' => 'L', 'Colour' => 'Red'], ['price' => 140, 'stock' => 4]);
        $this->assertSame(1, app(AlertEvaluator::class)->backInStock());
        $this->assertFalse($alert->fresh()->active);
    }

    public function test_alerts_validate_on_the_server(): void
    {
        [, $product] = $this->shirt();
        $buyer = $this->makeBuyer();
        $inStock = $product->variants()->where('stock', 5)->firstOrFail();

        $this->actingAs($buyer)->post(route('alerts.store', $product), ['kind' => 'back_in_stock', 'variant_id' => $inStock->id])->assertSessionHasErrors('kind', null, 'alert');
        $this->actingAs($buyer)->post(route('alerts.store', $product), ['kind' => 'price_drop', 'variant_id' => $inStock->id, 'target_price' => 500])->assertSessionHasErrors('target_price', null, 'alert');
        $this->actingAs($buyer)->post(route('alerts.store', $product), ['kind' => 'price_drop', 'variant_id' => 999999])->assertSessionHasErrors('variant_id', null, 'alert');
        $this->actingAs($buyer)->post(route('alerts.store', $product), ['kind' => 'price_drop', 'variant_id' => $inStock->id])->assertSessionHasNoErrors();
        $this->assertSame((string) $inStock->price, (string) ProductAlert::firstOrFail()->baseline_price, 'the price the person saw is that variant\'s, not the cheapest');
        $this->app['auth']->forgetGuards();
        $this->post(route('alerts.store', $product), ['kind' => 'price_drop'])->assertRedirect(route('login'));
    }

    public function test_the_alerts_page_lists_and_cancels_only_your_own_alerts(): void
    {
        $mine = $this->makeBuyer();
        $theirs = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(['name' => 'Alert Seller']), ['name' => 'Watched Kettle Distinct', 'stock' => 3]);
        $alerts = app(ProductAlerts::class);
        $mine1 = $alerts->subscribe($mine, $product, 'price_drop');
        $theirs1 = $alerts->subscribe($theirs, $product, 'price_drop');

        $this->get(route('account.alerts'))->assertRedirect(route('login'));
        $this->actingAs($mine)->get(route('account.alerts'))->assertOk()->assertSee('Watched Kettle Distinct')->assertSee('Cancel alert')->assertSee('Price drops');

        $this->actingAs($mine)->delete(route('alerts.destroy', $theirs1))->assertForbidden();
        $this->actingAs($mine)->delete(route('alerts.destroy', $mine1))->assertRedirect();
        $this->assertFalse($mine1->fresh()->active);
        $this->assertTrue($theirs1->fresh()->active);
        $this->actingAs($mine)->get(route('account.alerts'))->assertSee('No active alerts');
    }

    public function test_a_grid_of_hearts_costs_one_query(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $products = collect(range(1, 12))->map(fn ($n) => $this->makeListing($seller, ['stock' => 3]));
        foreach ($products->take(5) as $p) {
            app(Wishlists::class)->add($buyer, $p);
        }

        $this->actingAs($buyer);
        WishState::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = '';
        foreach ($products as $p) {
            $html .= view('components.wish-toggle', ['product' => $p])->render();
        }

        $this->assertSame(5, substr_count($html, 'aria-pressed="true"'));
        $this->assertSame(7, substr_count($html, 'aria-pressed="false"'));
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()), 'the saved ids are read once for the whole grid');
    }
}
