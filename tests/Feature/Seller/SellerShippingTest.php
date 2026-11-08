<?php

namespace Tests\Feature\Seller;

use App\Enums\ShippingMethodType;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** Shipping methods, region rules and the live "what a buyer in Gauteng would see" quote. */
class SellerShippingTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    private function valid(array $over = []): array
    {
        return array_merge(['name' => 'Standard courier', 'type' => 'flat', 'price' => '85.00', 'handling_days' => 1, 'window_min_days' => 2, 'window_max_days' => 5, 'is_active' => '1'], $over);
    }

    public function test_a_store_with_no_method_is_told_what_buyers_will_see(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->get(route('seller.shipping'))->assertOk()->assertSee('No shipping method yet')->assertSee('delivery is arranged with you');
        config(['emall.shipping.require_methods' => true]);
        $this->get(route('seller.shipping'))->assertSee('cannot buy from you until you add one');
    }

    public function test_the_three_kinds_are_created_with_what_each_needs(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->post(route('seller.shipping.store'), $this->valid())->assertSessionHasNoErrors();
        $this->post(route('seller.shipping.store'), $this->valid(['name' => 'Free over R1,000', 'type' => 'free_over', 'free_over' => '1000']))->assertSessionHasNoErrors();
        $this->post(route('seller.shipping.store'), $this->valid(['name' => 'Collect', 'type' => 'collection', 'price' => '55', 'collection_address' => '12 Long Street, open 9 to 5']))->assertSessionHasNoErrors();

        $methods = ShippingMethod::query()->where('store_id', $seller->store->id)->orderBy('id')->get();
        $this->assertSame([ShippingMethodType::Flat, ShippingMethodType::FreeOver, ShippingMethodType::Collection], $methods->pluck('type')->all());
        $this->assertSame('1000.00', $methods[1]->free_over);
        $this->assertSame('0.00', $methods[2]->price, 'collection is always free, whatever was typed');
        $this->assertNull($methods[0]->collection_address);
        $this->get(route('seller.shipping'))->assertSee('Standard courier')->assertSee('Free over R1,000')->assertSee('free, collected from you');
    }

    public function test_each_kind_is_refused_when_what_it_needs_is_missing_and_numbers_are_bounded(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->post(route('seller.shipping.store'), $this->valid(['type' => 'free_over']))->assertSessionHasErrors('free_over');
        $this->post(route('seller.shipping.store'), $this->valid(['type' => 'collection']))->assertSessionHasErrors('collection_address');
        $this->post(route('seller.shipping.store'), $this->valid(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('seller.shipping.store'), $this->valid(['type' => 'drone']))->assertSessionHasErrors('type');
        $this->post(route('seller.shipping.store'), $this->valid(['price' => '-1']))->assertSessionHasErrors('price');
        $this->post(route('seller.shipping.store'), $this->valid(['price' => '10.999']))->assertSessionHasErrors('price');
        $this->post(route('seller.shipping.store'), $this->valid(['window_min_days' => 5, 'window_max_days' => 2]))->assertSessionHasErrors('window_max_days');
        $this->post(route('seller.shipping.store'), $this->valid(['handling_days' => 99]))->assertSessionHasErrors('handling_days');
        $this->assertSame(0, ShippingMethod::count());
    }

    public function test_a_method_can_be_edited_switched_off_and_deleted_without_touching_placed_orders(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group] = $this->paidParcel($product);
        $method = ShippingMethod::query()->where('store_id', $seller->store->id)->first();
        $this->actingAs($seller);

        $this->patch(route('seller.shipping.update', $method), $this->valid(['name' => 'Renamed courier', 'price' => '99.00', 'is_active' => '0']))->assertSessionHasNoErrors();
        $this->assertSame(['Renamed courier', '99.00', false], [$method->fresh()->name, $method->fresh()->price, $method->fresh()->is_active]);

        $this->delete(route('seller.shipping.destroy', $method))->assertRedirect(route('seller.shipping'));
        $this->assertDatabaseMissing('shipping_methods', ['id' => $method->id]);
        $group->refresh();
        $this->assertSame(6000, $group->delivery_cents, 'the order keeps the price the buyer was shown');
        $this->assertSame('Courier', $group->method_name);
    }

    public function test_region_rules_are_validated_scoped_and_removable(): void
    {
        $seller = $this->makeSeller();
        $method = ShippingMethod::factory()->create(['store_id' => $seller->store->id]);
        $this->actingAs($seller);

        $this->post(route('seller.shipping.regions.store', $method), ['province' => 'Gauteng', 'price' => '45'])->assertSessionHasNoErrors();
        $this->post(route('seller.shipping.regions.store', $method), ['postal_from' => '8000', 'postal_to' => '8099'])->assertSessionHasNoErrors();
        $this->assertSame(2, $method->regions()->count());

        $this->post(route('seller.shipping.regions.store', $method), [])->assertSessionHasErrors('province');
        $this->post(route('seller.shipping.regions.store', $method), ['province' => 'Narnia'])->assertSessionHasErrors('province');
        $this->post(route('seller.shipping.regions.store', $method), ['postal_from' => '8000'])->assertSessionHasErrors('postal_to');
        $this->post(route('seller.shipping.regions.store', $method), ['postal_from' => '90', 'postal_to' => '99'])->assertSessionHasErrors('postal_from');
        $this->post(route('seller.shipping.regions.store', $method), ['postal_from' => '9000', 'postal_to' => '8000'])->assertSessionHasErrors('postal_from');
        $this->assertSame(2, $method->regions()->count());

        $region = $method->regions()->first();
        $other = ShippingMethod::factory()->create(['store_id' => $seller->store->id]);
        $this->delete(route('seller.shipping.regions.destroy', [$other, $region]))->assertNotFound(); // a rule is reached only through its own method
        $this->delete(route('seller.shipping.regions.destroy', [$method, $region]))->assertRedirect();
        $this->assertSame(1, $method->regions()->count());
    }

    public function test_the_buyer_preview_uses_the_carts_own_quote_for_the_province_the_seller_picks(): void
    {
        $seller = $this->makeSeller();
        $everywhere = ShippingMethod::factory()->create(['store_id' => $seller->store->id, 'name' => 'Everywhere courier', 'price' => 90]);
        $gauteng = ShippingMethod::factory()->create(['store_id' => $seller->store->id, 'name' => 'Gauteng express', 'price' => 40]);
        $gauteng->regions()->create(['country' => 'ZA', 'province' => 'Gauteng']);
        ShippingMethod::factory()->freeOver(300, 70)->create(['store_id' => $seller->store->id, 'name' => 'Free over R300']);
        $this->actingAs($seller);

        $gp = $this->get(route('seller.shipping', ['province' => 'Gauteng', 'basket' => '100']))->assertOk()->assertSee('A buyer in Gauteng with R100.00')->assertSee('Gauteng express')->assertSee('Everywhere courier')->assertSee('Spend R200.00 more for free delivery');
        $this->assertSame('Gauteng express', $gp->viewData('quote')->cheapest()->name);

        $wc = $this->get(route('seller.shipping', ['province' => 'Western Cape', 'basket' => '350']))->assertOk();
        $this->assertSame(['Everywhere courier', 'Free over R300'], collect($wc->viewData('quote')->options)->pluck('name')->sort()->values()->all(), 'a Gauteng-only method is not offered in the Western Cape');
        $this->assertSame('Free over R300', $wc->viewData('quote')->cheapest()->name);
        $this->assertSame(0, $wc->viewData('quote')->cheapest()->priceCents, 'free once the basket passes the threshold');

        $this->get(route('seller.shipping', ['province' => 'Atlantis']))->assertSessionHasErrors('province');
        $this->get(route('seller.shipping', ['postal_code' => '12']))->assertSessionHasErrors('postal_code');
    }

    public function test_a_collection_only_store_previews_collection_for_everyone(): void
    {
        $seller = $this->makeSeller();
        ShippingMethod::factory()->collection()->create(['store_id' => $seller->store->id]);

        $this->actingAs($seller)->get(route('seller.shipping', ['province' => 'Limpopo']))->assertOk()->assertSee('Collect from us')->assertSee('Free');
    }
}
