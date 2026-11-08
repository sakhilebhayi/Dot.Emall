<?php

namespace Tests\Feature\Storefront;

use App\Models\Address;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ShippingMethod;
use App\Services\Cart\CartManager;
use App\Services\Storefront\DeliveryRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

/** The product page in every state a shopper can meet it in. */
class ProductPageTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_single_variant_product_shows_its_price_stock_and_an_add_button_to_a_guest(): void
    {
        $product = $this->makeListing($this->makeSeller(['name' => 'Plain Goods Co']), ['name' => 'Enamel Kettle Distinct', 'price' => 249.5, 'stock' => 12]);

        $html = $this->get(route('products.show', $product))->assertOk()->assertSee('Enamel Kettle Distinct')->assertSee('Plain Goods Co')->assertSee('In stock')->assertSee('Add to cart')
            ->assertDontSee('Choose size')->getContent();

        $this->assertStringContainsString('R249.50', $html);
        $this->assertStringContainsString('No account needed to shop', $html);
        $this->assertStringNotContainsString('Only 12 left', $html, 'a quantity is shown only when it is five or fewer');
    }

    public function test_a_multi_variant_product_asks_for_the_choice_and_shows_a_from_price(): void
    {
        [, $product] = $this->shirt();

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('From', $html);
        $this->assertStringContainsString('R120.00', $html, 'the lowest price among the choices');
        $this->assertStringContainsString('Choose size and colour first', $html);
        $this->assertStringContainsString('name="opt[Size]"', $html);
        $this->assertStringContainsString('name="opt[Colour]"', $html);
        $this->assertMatchesRegularExpression('/name="variant_id" value=""/', $html, 'no variant until one is chosen');
        $this->assertStringNotContainsString('Only 3 left', $html, 'no stock claim before a variant is chosen');
    }

    public function test_choosing_a_variant_prices_it_and_shows_its_real_stock(): void
    {
        [, $product] = $this->shirt();
        $variant = $product->variants()->where('stock', 3)->first();

        $html = $this->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'S', 'Colour' => 'Red']]))->assertOk()->getContent();

        $this->assertStringContainsString('Only 3 left', $html);
        $this->assertStringContainsString('R120.00', $html);
        $this->assertStringContainsString('value="'.$variant->id.'"', $html);
        $this->assertStringContainsString('Size: S, Colour: Red', $html);
    }

    public function test_low_stock_is_a_real_number_at_five_or_fewer_and_nothing_above(): void
    {
        $seller = $this->makeSeller();
        $five = $this->makeListing($seller, ['name' => 'Five Left Thing', 'stock' => 5]);
        $six = $this->makeListing($seller, ['name' => 'Six Left Thing', 'stock' => 6]);

        $this->get(route('products.show', $five))->assertSee('Only 5 left');
        $this->get(route('products.show', $six))->assertDontSee('Only 6 left')->assertSee('In stock');
    }

    public function test_an_out_of_stock_product_cannot_be_added_and_offers_a_back_in_stock_alert(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Sold Out Lamp', 'stock' => 0]);

        $guest = $this->get(route('products.show', $product))->assertOk()->assertSee('Out of stock')->assertDontSee('Add to cart')->getContent();
        $this->assertStringContainsString('Sign in with InfoDot', $guest);

        $this->actingAs($this->makeBuyer())->get(route('products.show', $product))->assertSee('Tell me when it is back');
    }

    public function test_a_sold_out_choice_on_an_in_stock_product_is_selectable_with_its_own_alert(): void
    {
        [, $product] = $this->shirt();

        $html = $this->actingAs($this->makeBuyer())->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'L', 'Colour' => 'Red']]))->assertOk()->getContent();

        $this->assertStringContainsString('Out of stock', $html);
        $this->assertStringContainsString('Tell me when it is back', $html);
        $this->assertStringNotContainsString('name="quantity"', $html, 'there is nothing to add');
        $this->assertStringContainsString('is-soldout', $html);
    }

    public function test_a_combination_that_is_not_made_is_said_plainly(): void
    {
        [, $product] = $this->shirt();

        $this->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'M', 'Colour' => 'Blue']]))->assertOk()->assertSee('That combination is not made');
    }

    public function test_the_buy_box_can_be_fetched_on_its_own_for_the_script(): void
    {
        [, $product] = $this->shirt();

        $response = $this->withHeader('X-PDP-Fragment', 'buy')->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'M', 'Colour' => 'Red']]))->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('data-pdp-buy', $html);
        $this->assertStringNotContainsString('<html', $html, 'a fragment, not a page');
        $this->assertStringNotContainsString('Questions and answers', $html);
        $this->assertSame('X-PDP-Fragment', $response->headers->get('Vary'));
    }

    public function test_a_reference_price_appears_only_from_the_recorded_history_and_says_where_it_comes_from(): void
    {
        $seller = $this->makeSeller();
        Carbon::setTestNow(now()->subDays(60));
        $product = $this->makeListing($seller, ['name' => 'Reference Price Kettle', 'price' => 120, 'stock' => 10]);
        Carbon::setTestNow(now()->addDays(40));
        $product->refresh()->update(['price' => 100]);
        Carbon::setTestNow();

        // The seller-typed compare price must never print.
        $product->forceFill(['compare_price' => 999])->saveQuietly();

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Lowest price in the last 30 days', $html);
        $this->assertStringContainsString('R120.00', $html);
        $this->assertStringContainsString('from our own price record', $html);
        $this->assertStringNotContainsString('R999.00', $html);
    }

    public function test_no_reference_price_without_history_and_no_was_price_ever_from_the_seller(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 100, 'stock' => 10]);
        $product->forceFill(['compare_price' => 250])->saveQuietly();

        $this->get(route('products.show', $product))->assertOk()->assertDontSee('Lowest price in the last 30 days')->assertDontSee('R250.00');
    }

    public function test_no_countdowns_and_no_viewing_counters_exist_on_the_page(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        $html = strtolower($this->get(route('products.show', $product))->assertOk()->getContent());

        foreach (['viewing now', 'people are viewing', 'ends in', 'hurry', 'selling fast', 'countdown'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html);
        }
    }

    public function test_delivery_is_not_guessed_for_a_visitor_whose_region_is_unknown(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 100, 'stock' => 5]);

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Choose where it is going to see this', $html);
        $this->assertStringContainsString('Not known yet', $html, 'the total says so too');
        $this->assertStringContainsString('Where should it go?', $html);
    }

    public function test_a_chosen_province_prices_delivery_and_totals_it_with_an_estimate(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 5]);
        ShippingMethod::factory()->create(['store_id' => $seller->store->id, 'name' => 'Courier Standard', 'type' => 'flat', 'price' => 60, 'handling_days' => 1, 'window_min_days' => 2, 'window_max_days' => 4, 'is_active' => true]);

        $this->post(route('delivery.region'), ['province' => 'Gauteng', 'postal_code' => '2193', 'return' => '/products/'.$product->id])->assertRedirect('/products/'.$product->id);

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Delivering to', $html);
        $this->assertStringContainsString('Gauteng 2193', $html);
        $this->assertStringContainsString('R60.00', $html);
        $this->assertStringContainsString('R160.00', $html, 'price plus delivery');
        $this->assertStringContainsString('Estimated delivery', $html);
    }

    public function test_the_signed_in_buyers_default_address_sets_the_region(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 100, 'stock' => 5]);
        ShippingMethod::factory()->create(['store_id' => $seller->store->id, 'type' => 'flat', 'price' => 45, 'is_active' => true]);
        $buyer = $this->makeBuyer();
        Address::factory()->create(['user_id' => $buyer->id, 'province' => 'Western Cape', 'postal_code' => '8001', 'country' => 'ZA', 'is_default_shipping' => true]);

        $this->actingAs($buyer)->get(route('products.show', $product))->assertSee('Western Cape 8001')->assertSee('your saved address')->assertSee('R145.00');
    }

    public function test_the_region_form_refuses_a_made_up_province_and_a_bad_postcode(): void
    {
        $this->post(route('delivery.region'), ['province' => 'Narnia'])->assertSessionHasErrors('province');
        $this->post(route('delivery.region'), ['province' => 'Gauteng', 'postal_code' => '12'])->assertSessionHasErrors('postal_code');
        $this->post(route('delivery.region'), ['province' => 'Gauteng', 'return' => 'https://evil.example/x'])->assertRedirect('/products');
    }

    public function test_a_store_with_no_methods_says_delivery_is_arranged_and_adds_nothing(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 80, 'stock' => 5]);
        $this->post(route('delivery.region'), ['province' => 'Gauteng']);

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Arranged with the seller after you order', $html);
        $this->assertStringContainsString('R80.00', $html);
    }

    public function test_vat_is_shown_only_for_a_vat_registered_store(): void
    {
        $registered = $this->makeListing($this->makeSeller(['vat_registered' => true, 'vat_number' => '4123456789']), ['price' => 115, 'stock' => 5]);
        $unregistered = $this->makeListing($this->makeSeller(['vat_registered' => false]), ['price' => 115, 'stock' => 5]);

        $this->post(route('delivery.region'), ['province' => 'Gauteng']);

        $this->get(route('products.show', $registered))->assertSee('R15.00 VAT')->assertSee('Includes VAT');
        $this->get(route('products.show', $unregistered))->assertSee('is not VAT registered')->assertDontSee('Includes VAT');
    }

    public function test_a_guest_adds_to_the_session_cart_and_a_buyer_to_their_own(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 50, 'stock' => 5]);
        $variantId = $product->defaultVariant()->first()->id;

        $this->post(route('products.cart', $product), ['variant_id' => $variantId, 'quantity' => 2])->assertRedirect()->assertSessionHas('cart_link');
        $this->assertSame(2, app(CartManager::class)->count(null));

        $buyer = $this->makeBuyer();
        $this->actingAs($buyer)->post(route('products.cart', $product), ['quantity' => 1])->assertRedirect();
        $this->assertSame(1, app(CartManager::class)->count($buyer));
    }

    public function test_adding_a_multi_variant_product_without_a_choice_names_what_to_choose(): void
    {
        [, $product] = $this->shirt();

        $this->post(route('products.cart', $product), [])->assertRedirect()->assertSessionHasErrors('variant_id', null, 'cart');
        $this->assertSame(0, app(CartManager::class)->count(null));

        $this->followingRedirects()->post(route('products.cart', $product), [])->assertSee('Choose Size and Colour first.');
    }

    public function test_the_cart_refuses_more_than_the_real_stock_and_says_so(): void
    {
        [, $product] = $this->shirt();
        $variant = $product->variants()->where('stock', 3)->first();

        $this->post(route('products.cart', $product), ['variant_id' => $variant->id, 'quantity' => 9])->assertSessionHasErrors('quantity', null, 'cart');
        $this->assertSame(0, app(CartManager::class)->count(null));
    }

    public function test_a_variant_from_another_product_cannot_be_added_through_this_page(): void
    {
        [, $product] = $this->shirt();
        $other = $this->makeListing($this->makeSeller(), ['stock' => 5]);

        $this->post(route('products.cart', $product), ['variant_id' => $other->defaultVariant()->first()->id])->assertSessionHasErrors('variant_id', null, 'cart');
        $this->assertSame(0, app(CartManager::class)->count(null));
    }

    public function test_the_seller_viewing_their_own_listing_cannot_add_it(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);

        $this->actingAs($seller)->get(route('products.show', $product))->assertOk()->assertSee('This is your own listing');
        $this->actingAs($seller)->post(route('products.cart', $product), ['variant_id' => $product->defaultVariant()->first()->id])->assertSessionHasErrors('quantity', null, 'cart');
    }

    public function test_an_unlisted_or_pending_product_is_a_404_for_strangers_and_visible_to_its_own_store_and_the_operator(): void
    {
        $seller = $this->makeSeller();
        $pending = $this->makeListing($seller, ['name' => 'Pending Listing Item', 'status' => 'pending_approval']);
        $off = $this->makeListing($seller, ['name' => 'Switched Off Item', 'is_active' => false]);

        foreach ([$pending, $off] as $product) {
            $this->get(route('products.show', $product))->assertNotFound();
            $this->actingAs($this->makeBuyer())->get(route('products.show', $product))->assertNotFound();
        }

        $this->actingAs($seller)->get(route('products.show', $pending))->assertOk()->assertSee('Only you can see this page')->assertSee('Pending review')->assertDontSee('application/ld+json');
        $this->actingAs($this->operator())->get(route('products.show', $off))->assertOk()->assertSee('Unlisted');
        $this->actingAs($seller)->get(route('products.show', $pending))->assertSee('noindex', false);
    }

    public function test_a_listing_in_a_closed_store_is_a_404(): void
    {
        $seller = $this->makeSeller(['is_active' => false]);
        $product = $this->makeListing($seller);

        $this->get(route('products.show', $product))->assertNotFound();
    }

    public function test_structured_data_is_a_product_with_an_offer_and_a_rating_only_when_real(): void
    {
        $seller = $this->makeSeller();
        $rated = $this->makeListing($seller, ['name' => 'Rated Pot Distinct', 'price' => 200, 'stock' => 5]);
        $buyer = $this->deliveredBuyerOf($rated);
        $this->actingAs($buyer)->post(route('reviews.store', $rated), ['rating' => 4, 'body' => 'Solid pot.']);
        $plain = $this->makeListing($seller, ['name' => 'Unrated Pan Distinct', 'price' => 90, 'stock' => 5]);

        $this->app['auth']->forgetGuards();
        $withRating = $this->get(route('products.show', $rated))->getContent();
        preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $withRating, $m);
        $data = json_decode($m[1], true);
        $this->assertSame('Product', $data['@type']);
        $this->assertSame('Rated Pot Distinct', $data['name']);
        $this->assertSame('200.00', $data['offers']['price']);
        $this->assertSame('ZAR', $data['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability']);
        $this->assertSame(1, $data['aggregateRating']['reviewCount']);

        preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $this->get(route('products.show', $plain))->getContent(), $m2);
        $this->assertArrayNotHasKey('aggregateRating', json_decode($m2[1], true));
    }

    public function test_recently_viewed_records_a_view_once_per_visit_not_per_review_sort(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->get(route('products.show', $product));
        $this->actingAs($buyer)->get(route('products.show', ['product' => $product, 'reviews_sort' => 'newest']));
        $this->actingAs($buyer)->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'S']]));

        $this->assertSame(1, (int) DB::table('commerce_events')->where('name', 'product_view')->count());
    }

    public function test_the_seller_looking_at_their_own_listing_is_not_a_view(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5]);

        $this->actingAs($seller)->get(route('products.show', $product));

        $this->assertSame(0, (int) DB::table('commerce_events')->where('name', 'product_view')->count());
    }

    public function test_the_region_helper_ignores_a_corrupted_session_value(): void
    {
        $this->withSession([DeliveryRegion::SESSION_KEY => ['province' => 'Mars', 'postal_code' => 'abcd']]);
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);

        $this->get(route('products.show', $product))->assertOk()->assertSee('Where should it go?');
    }

    public function test_the_page_costs_the_same_number_of_queries_for_a_small_and_a_large_catalogue_of_options(): void
    {
        [, $small] = $this->shirt();
        $count = function ($product) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('products.show', ['product' => $product, 'opt' => ['Size' => 'S', 'Colour' => 'Red']]))->assertOk();

            return count(DB::getQueryLog());
        };

        $before = $count($small);

        // Twelve more variants on the same product, and more reviews: the number of queries must not move with them.
        $variants = app(\App\Services\Catalogue\ProductVariants::class);
        $variants->defineOptions($small, ['Size' => ['S', 'M', 'L', 'XL', 'XXL'], 'Colour' => ['Red', 'Blue', 'Green']]);
        foreach (['XL', 'XXL'] as $size) {
            foreach (['Red', 'Blue', 'Green'] as $colour) {
                $variants->saveVariant($small, ['Size' => $size, 'Colour' => $colour], ['price' => 150, 'stock' => 4]);
            }
        }

        $after = $count($small->fresh());

        $this->assertLessThanOrEqual($before + 1, $after, "page queries went from {$before} to {$after}");
        $this->assertLessThan(60, $after, 'a product page stays within a sane query budget');
    }
}
