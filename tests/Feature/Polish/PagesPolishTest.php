<?php

namespace Tests\Feature\Polish;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

/** The outside jury's page defects (R-43 polish, decisions D-190 to D-199): each one stays fixed. */
class PagesPolishTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsOperatorWorld;
    use BuildsPayments;
    use BuildsSellerDesk;
    use BuildsShopFlows;
    use RefreshDatabase;

    private function shopWithSavedAddress(): array
    {
        $this->enablePaystack();
        $seller = $this->makeSeller(['name' => 'Polish Stores']);
        $this->withDelivery($seller, ['price' => 60]);
        $product = $this->makeListing($seller, ['name' => 'Polish Kettle', 'price' => 200, 'stock' => 9]);
        $buyer = $this->makeBuyer();
        $saved = Address::create(['user_id' => $buyer->id, 'is_default_shipping' => true] + $this->address(['recipient_name' => 'Saved Sipho']));
        $this->addToCart($buyer, $product);

        return [$buyer, $saved, $product];
    }

    // ---- #3 checkout --------------------------------------------------------------------------------------------------

    public function test_a_saved_address_and_a_preselected_option_leave_pay_enabled_and_mark_the_steps_done(): void
    {
        [$buyer] = $this->shopWithSavedAddress();

        $page = $this->actingAs($buyer)->get(route('checkout.show'))->assertOk();

        $page->assertSee('Pay R260.00 securely')
            ->assertDontSee('Set your delivery details first.')
            ->assertDontSee('Enter an address', false)
            ->assertSee('Done: ', false)
            ->assertSee('Your saved address and a delivery option for each parcel are selected');
        $this->assertTrue($page->viewData('deliveryDone'));
        $this->assertSame(3, $page->viewData('current'));
    }

    public function test_the_server_still_validates_and_recomputes_when_delivery_was_never_pressed(): void
    {
        [$buyer, $saved] = $this->shopWithSavedAddress();
        $this->actingAs($buyer)->get(route('checkout.show'))->assertOk();

        // A tampered total is refused and nothing is ordered.
        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 1, 'address_id' => $saved->id, 'terms' => '1'])
            ->assertRedirect(route('checkout.show'))->assertSessionHas('price_changed');
        $this->assertSame(0, Order::count());

        // Somebody else's address is not accepted, whatever the page showed.
        $other = Address::create(['user_id' => $this->makeBuyer()->id] + $this->address());
        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 26000, 'address_id' => $other->id, 'terms' => '1'])
            ->assertSessionHasErrors('address_id');
        $this->assertSame(0, Order::count());

        // The terms box is still required.
        $this->actingAs($buyer)->post(route('checkout.place'), ['checkout_token' => (string) Str::uuid(), 'expected_total_cents' => 26000, 'address_id' => $saved->id])
            ->assertSessionHasErrors('terms');
        $this->assertSame(0, Order::count());
    }

    public function test_a_guest_still_has_to_ask_for_delivery_options_first(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['price' => 60]);
        $this->addToCart(null, $this->makeListing($seller, ['price' => 200, 'stock' => 5]));

        $this->get(route('checkout.show'))->assertOk()->assertSee('Set your delivery details first.')->assertSee('Show delivery options');
    }

    public function test_choosing_a_different_address_shows_its_own_form_and_a_saved_one_has_no_form_heading(): void
    {
        [$buyer] = $this->shopWithSavedAddress();

        $html = $this->actingAs($buyer)->get(route('checkout.show'))->getContent();
        $this->assertStringContainsString('id="co-newaddr"', $html, 'the form exists (shown by CSS only while "Use a different address" is chosen)');
        $this->assertStringNotContainsString('<summary>Enter an address</summary>', $html);
    }

    // ---- #12 search ---------------------------------------------------------------------------------------------------

    public function test_the_word_that_became_a_category_still_ranks_the_products_that_say_it_in_their_title(): void
    {
        $this->setUpCatalogue();
        $store = $this->store('Rank Traders');
        $av = $this->category('TV, audio and video');
        // The speaker is listed later and is newest, so a plain "newest first" fallback would put it on top.
        $tv = $this->listing($store, 'Hisense 50 Inch 4K Smart TV', 7999.00, ['category_id' => $av->id, 'stock' => 3], 'Hisense');
        $speaker = $this->listing($store, 'Portable Bluetooth Speaker', 649.00, ['category_id' => $av->id, 'stock' => 9], 'JBL');
        $this->reindex();

        $result = app(SearchEngine::class)->search(new SearchRequest(text: 'tv'));

        $this->assertSame([$tv->id, $speaker->id], $result->productIds(), 'both are in the category the word named, the product with TV in its title is first');
    }

    public function test_a_long_brand_list_shows_a_few_and_a_show_more_with_the_rest_inside(): void
    {
        $options = collect(range(1, 10))->map(fn ($i) => ['value' => "B$i", 'label' => "Brand $i", 'count' => 11 - $i, 'checked' => $i === 9])->all();

        $html = view('components.ds.facet-group', ['title' => 'Brand', 'name' => 'brand', 'options' => $options, 'limit' => 6])->render();

        $this->assertStringContainsString('Show 3 more', $html, 'ten options, six shown, the ticked ninth stays visible, three behind the disclosure');
        $this->assertSame(10, substr_count($html, 'class="ds-facet__row"'));
        $this->assertStringContainsString('<details class="ds-facet__more">', $html);

        $short = view('components.ds.facet-group', ['title' => 'Brand', 'name' => 'brand', 'options' => array_slice($options, 0, 6), 'limit' => 6])->render();
        $this->assertStringNotContainsString('ds-facet__more', $short);
    }

    // ---- #14 the product page ---------------------------------------------------------------------------------------

    public function test_the_price_small_print_is_one_fact_per_line_behind_a_disclosure_and_nothing_is_dropped(): void
    {
        $seller = $this->makeSeller(['name' => 'Small Print Stores', 'vat_registered' => true]);
        $this->withDelivery($seller, ['price' => 60]);
        $product = $this->makeListing($seller, ['name' => 'Honest Kettle', 'price' => 200, 'stock' => 9]);

        $html = $this->get(route('products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('How this price is worked out', $html);
        $this->assertStringNotContainsString('class="pdp-facts"', $html);
        $this->assertStringContainsString('class="pdp-work"', $html);
        $this->assertStringContainsString('Small Print Stores ships it, not Emall.', $html);
        $this->assertStringContainsString('worked out in your cart', $html);
        $this->assertStringContainsString('The seller ships it, not Emall.', $html, 'who ships it is still said beside the stock');
        // The disclosure sits after the buy form, not before the price.
        $this->assertLessThan(strpos($html, 'id="price-work"'), strpos($html, 'id="buy-form"'));
    }

    // ---- #21 / #22 orders -----------------------------------------------------------------------------------------------

    private function paid(User $buyer, string $name, ?string $placed = null): Order
    {
        static $seller = null, $variant = 0;
        $seller = $this->makeSeller(['name' => 'Polish Orders '.(++$variant)]);
        $this->withDelivery($seller, ['price' => 60]);
        $this->enablePaystack();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => $name, 'price' => 100, 'stock' => 9]), 1]]);
        $order = $this->payOrder($order, (string) random_int(1_000_000_000, 9_999_999_999))->order->fresh();

        if ($placed !== null) {
            $order->forceFill(['created_at' => $placed])->save();
        }

        return $order->fresh();
    }

    public function test_the_orders_list_is_by_date_the_whole_row_opens_and_there_is_one_secondary_action(): void
    {
        $buyer = $this->makeBuyer();
        $older = $this->paid($buyer, 'Older Kettle', '2026-09-01 10:00:00');
        $newer = $this->paid($buyer, 'Newer Kettle', '2026-10-01 10:00:00');
        // Placed first (lower id) but dated latest: by id it would be last.
        $latest = $this->paid($buyer, 'Latest Kettle', '2026-11-01 10:00:00');
        $older->forceFill(['created_at' => '2026-11-05 10:00:00'])->save();

        $page = $this->actingAs($buyer)->get(route('orders.index'))->assertOk();

        $page->assertSeeInOrder([$older->reference(), $latest->reference(), $newer->reference()]);
        $page->assertSee('co-orders__open', false)->assertSee('Buy again')->assertDontSee('>Open<', false);
        $this->assertSame(3, substr_count($page->getContent(), 'Buy again<span class="ds-visually-hidden">'));
    }

    public function test_the_waybill_says_not_recorded_for_a_step_with_no_time_says_where_it_is_and_has_a_key(): void
    {
        $buyer = $this->makeBuyer();
        [$seller, $product] = $this->shippingSeller();
        [$order, $group, $line] = $this->paidParcel($product, $buyer);
        $this->shipParcel($group);

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        $page->assertSee('Not recorded')->assertSee('Next step:')->assertSee('What the markers mean')->assertSee('ds-waybill--across', false);
    }

    // ---- #15 the seller desk --------------------------------------------------------------------------------------------

    public function test_seller_parcels_are_ordered_by_the_date_the_order_was_placed(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$first] = $this->paidParcel($product);
        [$second] = $this->paidParcel($product);
        [$third] = $this->paidParcel($product);
        $first->forceFill(['created_at' => '2026-11-07 09:00:00'])->save();
        $second->forceFill(['created_at' => '2026-10-01 09:00:00'])->save();
        $third->forceFill(['created_at' => '2026-09-01 09:00:00'])->save();

        // "To ship" is oldest first: the one to do first.
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertSeeInOrder([$third->reference(), $second->reference(), $first->reference()]);

        // The dashboard's recent orders are newest first, by date, not by id.
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertSeeInOrder([$first->reference(), $second->reference(), $third->reference()]);
    }

    public function test_the_dashboard_has_no_ship_to_column_when_nobody_is_left_to_ship_to(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [, $group] = $this->paidParcel($product);
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertSee('Ship to');

        $this->shipParcel($group);
        $this->actingAs($seller)->post(route('seller.orders.deliver', $group))->assertRedirect();
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertDontSee('Ship to')->assertDontSee('Not shown after dispatch');
    }

    // ---- #16, #18, #19 desk views ---------------------------------------------------------------------------------------

    public function test_the_product_editor_has_one_save_per_form_with_an_unsaved_state_and_an_aligned_photo_row(): void
    {
        [$seller, $product] = $this->shippingSeller();

        $html = $this->actingAs($seller)->get(route('seller.products.edit', $product))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(1, substr_count($html, 'data-dirty-form'));
        $this->assertSame(substr_count($html, 'data-dirty-form'), substr_count($html, 'data-dirty-status'), 'every form that can be edited says when it has unsaved changes');
        $this->assertSame(1, substr_count($html, '>Save details<'));
    }

    public function test_the_operator_phone_bar_uses_the_short_label_and_the_rail_keeps_the_long_one(): void
    {
        $html = $this->actingAs($this->makeOperator())->get(route('operator.home'))->assertOk()->getContent();

        $this->assertStringContainsString('<span>Queue</span>', $html);
        $this->assertStringContainsString('<span>Needs a person</span>', $html);
    }

    public function test_the_metrics_chart_axes_are_real_text_with_three_ticks_and_the_csv_has_a_download_icon(): void
    {
        $operator = $this->makeOperator();
        $this->paidOrderForMetrics();

        $html = $this->actingAs($operator)->get(route('operator.metrics'))->assertOk()->getContent();

        $this->assertStringContainsString('op-plot__y', $html);
        $this->assertSame(0, substr_count($html, 'op-chart__text'), 'no axis text is drawn inside the scaled SVG any more');
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'class="op-plot__y"'), 'three ticks on each of the two charts');
        $this->assertStringContainsString('M12 4v12', $html, 'the download arrow');
        $this->assertStringNotContainsString('M12 16V4', $html, 'not the upload arrow');
    }

    private function paidOrderForMetrics(): void
    {
        [, $product] = $this->shippingSeller();
        $this->paidParcel($product);
    }
}
