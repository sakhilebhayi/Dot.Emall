<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\PromoCode;
use App\Models\ShippingMethod;
use App\Services\Community\ProductQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/**
 * A seller never sees, or touches, another store's orders, customers, ledger, analytics, listings, shipping or returns. Strangers,
 * guests, colleagues in the owner's team and operators without a store of their own are sent to the "Sell on Emall" page.
 */
class SellerAccessTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    /** @return array<int, string> every GET page of the desk that needs a store and carries no id */
    private function desk(): array
    {
        return [
            route('seller.dashboard'), route('seller.store.status'), route('seller.store.edit'), route('seller.products.index'), route('seller.products.create'),
            route('seller.shipping'), route('seller.orders.index'), route('seller.returns'), route('seller.promo-codes.index'), route('seller.analytics'),
            route('seller.ledger'), route('seller.feedback'), route('seller.analytics.export', 'orders'), route('seller.ledger.export'),
        ];
    }

    public function test_a_guest_is_sent_to_sign_in_from_every_desk_page(): void
    {
        foreach ($this->desk() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_people_without_a_store_are_sent_to_the_sell_on_emall_page(): void
    {
        [$owner] = $this->shippingSeller();
        $stranger = $this->makeBuyer();
        $colleague = $this->colleagueOf($owner);
        $operator = $this->operator();

        foreach ([$stranger, $colleague, $operator] as $person) {
            foreach ($this->desk() as $url) {
                $this->actingAs($person)->get($url)->assertRedirect(route('sell.start'));
            }
        }
    }

    public function test_a_colleague_in_the_owners_team_sees_nothing_of_the_owners_store(): void
    {
        [$owner, $product] = $this->shippingSeller(['name' => 'Owner Only Trading']);
        [$order, $group] = $this->paidParcel($product);
        $colleague = $this->colleagueOf($owner);

        $this->actingAs($colleague)->get(route('seller.orders.show', $group))->assertRedirect(route('sell.start'));
        $this->actingAs($colleague)->post(route('seller.orders.ship', $group))->assertRedirect(route('sell.start'));
        $this->actingAs($colleague)->get(route('seller.products.edit', $product))->assertForbidden();
    }

    public function test_another_seller_gets_nothing_from_a_parcel_a_return_or_a_shipment_that_is_not_theirs(): void
    {
        [$a, $product] = $this->shippingSeller(['name' => 'Alpha Wares']);
        $b = $this->makeSeller(['name' => 'Bravo Wares']);
        [$order, $group, $line, $buyer] = $this->paidParcel($product);
        $shipment = $this->shipParcel($group);
        $order = $this->advanceAll($order->fresh(), OrderStatus::Delivered);
        $return = $this->returnFor($line, $buyer);

        $this->actingAs($b);
        foreach ([route('seller.orders.show', $group), route('seller.orders.slip', $group), route('seller.returns.show', $return), route('seller.returns.photo', [$return, 0])] as $url) {
            $this->get($url)->assertNotFound();
        }
        foreach ([route('seller.orders.pack', $group), route('seller.orders.ship', $group), route('seller.orders.deliver', $group), route('seller.orders.cancel', $group), route('seller.orders.note', $group), route('seller.returns.approve', $return), route('seller.returns.decline', $return), route('seller.returns.receive', $return)] as $url) {
            $this->post($url, ['reason' => 'nope nope', 'body' => 'x', 'item_ids' => [$line->id]])->assertNotFound();
        }
        $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);

        $this->post(route('seller.shipments.events.store', $shipment), ['description' => 'Hacked'])->assertNotFound();
        $this->patch(route('seller.shipments.update', $shipment), ['tracking_number' => 'HACK12345'])->assertNotFound();
        $this->assertSame(0, $shipment->events()->count());
    }

    public function test_the_lists_of_another_store_never_contain_this_stores_rows(): void
    {
        [$a, $product] = $this->shippingSeller(['name' => 'Alpha Wares'], ['name' => 'Alpha Private Kettle Model']);
        $b = $this->makeSeller(['name' => 'Bravo Wares']);
        [$order, $group, $line, $buyer] = $this->paidParcel($product);
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'ALPHAONLY']);

        $this->actingAs($b);
        $this->get(route('seller.orders.index', ['tab' => 'to_ship']))->assertOk()->assertDontSee($order->reference())->assertDontSee('Alpha Private Kettle Model');
        $this->get(route('seller.products.index'))->assertOk()->assertDontSee('Alpha Private Kettle Model');
        $this->get(route('seller.ledger'))->assertOk()->assertDontSee($order->reference());
        $this->get(route('seller.promo-codes.index'))->assertOk()->assertDontSee('ALPHAONLY');
        $this->get(route('seller.analytics'))->assertOk()->assertSee('No data yet');
        $this->get(route('seller.dashboard'))->assertOk()->assertSee('0 parcels to pack and ship')->assertDontSee($order->reference());
        $csv = $this->get(route('seller.analytics.export', ['what' => 'orders', 'period' => 30]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Alpha Private Kettle Model', $csv);
        $this->assertStringNotContainsString('Alpha Private Kettle Model', $this->get(route('seller.analytics.export', ['what' => 'products', 'period' => 30]))->streamedContent());
        $this->assertSame(1, substr_count($this->get(route('seller.ledger.export'))->streamedContent(), "\n"), 'the ledger file has the header and nothing else');
    }

    public function test_another_seller_cannot_edit_a_listing_a_method_or_a_code_that_is_not_theirs(): void
    {
        [$a, $product] = $this->shippingSeller();
        $b = $this->makeSeller();
        $method = ShippingMethod::query()->where('store_id', $a->store->id)->first();
        $code = PromoCode::factory()->create(['store_id' => $a->store->id]);

        $this->actingAs($b);
        $this->get(route('seller.products.edit', $product))->assertForbidden();
        $this->patch(route('seller.products.update', $product), ['name' => 'x', 'price' => 1, 'stock' => 1])->assertForbidden();
        $this->post(route('seller.products.options', $product), [])->assertForbidden();
        $this->post(route('seller.products.variants', $product), ['rows' => []])->assertForbidden();
        $this->post(route('seller.products.stock', $product), ['stock' => []])->assertForbidden();
        $this->patch(route('seller.products.attributes', $product), [])->assertForbidden();
        $this->post(route('seller.products.duplicate', $product))->assertForbidden();
        $this->post(route('seller.products.resubmit', $product))->assertForbidden();
        $this->get(route('seller.products.stock-history', $product))->assertForbidden();
        $this->patch(route('seller.shipping.update', $method), ['name' => 'x'])->assertForbidden();
        $this->delete(route('seller.shipping.destroy', $method))->assertForbidden();
        $this->post(route('seller.shipping.regions.store', $method), ['province' => 'Gauteng'])->assertForbidden();
        $this->patch(route('seller.promo-codes.toggle', $code))->assertForbidden();
        $this->delete(route('seller.promo-codes.destroy', $code))->assertForbidden();
        $this->assertTrue($code->fresh()->is_active);
    }

    public function test_bulk_switching_ignores_ids_that_are_not_the_sellers_own(): void
    {
        [$a, $product] = $this->shippingSeller();
        $b = $this->makeSeller();
        $mine = $this->makeListing($b);

        $this->actingAs($b)->post(route('seller.products.bulk'), ['action' => 'deactivate', 'ids' => [$product->id, $mine->id]])->assertRedirect();

        $this->assertTrue($product->fresh()->is_active, 'somebody else\'s listing is untouched');
        $this->assertFalse($mine->fresh()->is_active);
    }

    public function test_the_questions_and_reviews_of_another_store_cannot_be_answered(): void
    {
        [$a, $product] = $this->shippingSeller();
        $b = $this->makeSeller();
        $asker = $this->makeBuyer();
        $question = app(ProductQuestions::class)->ask($asker, $product, 'Does this kettle switch itself off?');

        $this->actingAs($b)->post(route('seller.questions.answer', $question), ['body' => 'Yes it does.'])->assertNotFound();
        $this->assertSame(0, $question->fresh()->answers_count);
    }
}
