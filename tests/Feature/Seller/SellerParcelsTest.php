<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\SellerOrderNote;
use App\Models\ShippingMethod;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** The orders page and one parcel: tabs, packing, shipping with a carrier, the unpaid block, delivery, cancelling, notes, the slip and privacy. */
class SellerParcelsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    public function test_a_paid_parcel_is_under_to_ship_and_moves_through_the_tabs_as_it_is_shipped_and_delivered(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group, $line] = $this->paidParcel($product);

        $page = $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertSee($order->reference())->assertSee('Pack and ship');
        $this->assertSame(1, $page->viewData('counts')['to_ship']);
        $this->assertSame(0, $page->viewData('counts')['on_the_way']);

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy', 'tracking_number' => 'TCG123456'])
            ->assertRedirect(route('seller.orders.show', $group));
        $this->assertSame(OrderStatus::Shipped, $this->lineStatus($line));
        $this->assertSame(1, $this->actingAs($seller)->get(route('seller.orders.index', ['tab' => 'on_the_way']))->viewData('counts')['on_the_way']);

        $this->actingAs($seller)->post(route('seller.orders.deliver', $group))->assertRedirect();
        $this->assertSame(OrderStatus::Delivered, $this->lineStatus($line));
        $this->actingAs($seller)->get(route('seller.orders.index', ['tab' => 'delivered']))->assertSee($order->reference());
    }

    public function test_shipping_records_the_carrier_and_a_tracking_link_only_for_an_allowlisted_carrier(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group] = $this->paidParcel($product);

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy', 'tracking_number' => 'TCG123456', 'note' => 'Left with the courier'])->assertSessionHasNoErrors()->assertRedirect();
        $shipment = $group->shipments()->first();
        $this->assertSame('The Courier Guy', $shipment->carrier_name);
        $this->assertStringStartsWith('https://portal.thecourierguy.co.za/', $shipment->tracking_url);

        [, $group2] = $this->paidParcel($product);
        $this->actingAs($seller)->post(route('seller.orders.ship', $group2), ['carrier' => 'other', 'carrier_name' => 'Our own bakkie', 'tracking_number' => 'BAKKIE-001'])->assertSessionHasNoErrors()->assertRedirect();
        $own = $group2->shipments()->first();
        $this->assertSame('Our own bakkie', $own->carrier_name);
        $this->assertNull($own->tracking_url, 'a carrier that is not on the list gets plain text, never a link');

        $page = $this->actingAs($seller)->get(route('seller.orders.show', $group2))->assertOk();
        $page->assertSee('Our own bakkie')->assertSee('No tracking link');
        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertSee('Track with the carrier');
    }

    public function test_a_bad_tracking_number_or_a_carrier_off_the_list_is_refused_with_words(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [, $group, $line] = $this->paidParcel($product);

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy', 'tracking_number' => '<script>'])->assertSessionHasErrors('tracking_number');
        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'made_up_courier'])->assertSessionHasErrors('carrier_key');
        $this->assertSame(OrderStatus::Pending, $this->lineStatus($line), 'nothing shipped');
    }

    public function test_an_unpaid_order_cannot_be_packed_or_shipped_and_the_page_says_so(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $this->enablePaystack();
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]); // placed, never paid
        $group = $order->items()->first()->group;

        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertOk()->assertSee('Do not ship this yet');
        $this->actingAs($seller)->get(route('seller.orders.index', ['tab' => 'unpaid']))->assertSee($order->reference());
        $this->assertSame(0, $this->actingAs($seller)->get(route('seller.orders.index'))->viewData('counts')['to_ship']);

        $this->actingAs($seller)->post(route('seller.orders.pack', $group))->assertSessionHasErrors('status');
        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy'])->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Pending, $this->lineStatus($order->items()->first()));
        $this->assertSame(0, $group->shipments()->count());
    }

    public function test_illegal_moves_are_refused(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [, $group, $line] = $this->paidParcel($product);

        // Delivered before shipped: nothing is shipped, so there is nothing to deliver.
        $this->actingAs($seller)->post(route('seller.orders.deliver', $group))->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Pending, $this->lineStatus($line));

        // A delivery parcel is not "ready for collection".
        $this->actingAs($seller)->post(route('seller.orders.ready', $group))->assertSessionHasErrors('status');

        // Shipping twice: the second time has nothing left to ship.
        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy'])->assertRedirect();
        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy'])->assertSessionHasErrors('status');
        $this->assertSame(1, $group->shipments()->count());

        // Once shipped it can no longer be cancelled by the seller.
        $this->actingAs($seller)->post(route('seller.orders.cancel', $group), ['item_ids' => [$line->id], 'reason' => 'Changed our mind'])->assertSessionHasErrors('item_ids');
        $this->assertSame(OrderStatus::Shipped, $this->lineStatus($line));
    }

    public function test_packing_then_shipping_part_of_a_parcel_in_two_shipments(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $second = $this->makeListing($seller, ['price' => 50, 'stock' => 5, 'name' => 'Second Item In The Parcel']);
        $this->enablePaystack();
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1], [$second, 1]]);
        $this->payOrder($order);
        $group = $order->items()->first()->group;
        [$a, $b] = $group->items()->orderBy('id')->get()->all();

        $this->actingAs($seller)->post(route('seller.orders.pack', $group))->assertRedirect();
        $this->assertSame(OrderStatus::Processing, $this->lineStatus($a));

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'aramex', 'tracking_number' => '123456789', 'order_item_ids' => [$a->id]])->assertRedirect();
        $this->assertSame([OrderStatus::Shipped, OrderStatus::Processing], [$this->lineStatus($a), $this->lineStatus($b)]);
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertSee('Pack and ship', false); // still to ship: one line is left

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'aramex', 'tracking_number' => '987654321'])->assertRedirect();
        $this->assertSame(2, $group->shipments()->count());
    }

    public function test_tracking_updates_are_the_sellers_own_words_and_can_be_corrected(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [, $group] = $this->paidParcel($product);
        $shipment = $this->shipParcel($group);

        $this->actingAs($seller)->post(route('seller.shipments.events.store', $shipment), ['description' => 'Handed to the courier', 'location' => 'Cape Town depot'])->assertRedirect();
        $this->assertSame('Handed to the courier', $shipment->events()->first()->description);
        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertSee('Handed to the courier')->assertSee('Cape Town depot');

        $this->actingAs($seller)->post(route('seller.shipments.events.store', $shipment), ['description' => ''])->assertSessionHasErrors('description');
        $this->actingAs($seller)->post(route('seller.shipments.events.store', $shipment), ['description' => str_repeat('x', 191)])->assertSessionHasErrors('description');

        $this->actingAs($seller)->patch(route('seller.shipments.update', $shipment), ['carrier' => 'aramex', 'tracking_number' => '555666777'])->assertRedirect();
        $this->assertSame('Aramex', $shipment->fresh()->carrier_name);
    }

    public function test_a_collection_parcel_goes_ready_then_collected_and_cannot_be_shipped(): void
    {
        $seller = $this->makeSeller();
        ShippingMethod::factory()->collection()->create(['store_id' => $seller->store->id]);
        $product = $this->makeListing($seller, ['price' => 80, 'stock' => 3]);
        $this->enablePaystack();
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);
        $this->payOrder($order);
        $group = $order->items()->first()->group;
        $line = $order->items()->first();

        $this->actingAs($seller)->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy'])->assertSessionHasErrors('status');
        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertSee('Mark ready for collection');

        $this->actingAs($seller)->post(route('seller.orders.ready', $group))->assertRedirect();
        $this->assertSame(OrderStatus::ReadyForCollection, $this->lineStatus($line));
        $this->actingAs($seller)->get(route('seller.orders.index', ['tab' => 'on_the_way']))->assertSee($order->reference());

        $this->actingAs($seller)->post(route('seller.orders.deliver', $group))->assertRedirect();
        $this->assertSame(OrderStatus::Collected, $this->lineStatus($line));
    }

    public function test_cancelling_a_line_needs_a_reason_restocks_it_and_reverses_the_earnings(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group, $line] = $this->paidParcel($product);
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7001, 'status' => 'pending']], 200)]);
        $stockBefore = $product->fresh()->stock;

        $this->actingAs($seller)->post(route('seller.orders.cancel', $group), ['item_ids' => [$line->id]])->assertSessionHasErrors(['reason']);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $group), ['item_ids' => [], 'reason' => 'Out of stock after all'])->assertSessionHasErrors('item_ids');
        $this->assertSame(OrderStatus::Pending, $this->lineStatus($line));

        $this->actingAs($seller)->post(route('seller.orders.cancel', $group), ['item_ids' => [$line->id], 'reason' => 'Out of stock after all'])->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $this->lineStatus($line));
        $this->assertSame($stockBefore + 1, $product->fresh()->stock, 'the unit is back on the shelf');
        $this->assertSame(0, (int) LedgerEntry::query()->where('order_group_id', $group->id)->sum('net_cents'), 'the sale and its reversal net to nothing');
        $this->assertDatabaseHas('order_status_histories', ['order_item_id' => $line->id, 'to_status' => 'cancelled', 'note' => 'Out of stock after all']);
    }

    public function test_notes_are_private_and_scoped_to_the_parcel(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group, , $buyer] = $this->paidParcel($product);

        $this->actingAs($seller)->post(route('seller.orders.note', $group), ['body' => 'Customer asked for a gift wrap'])->assertRedirect();
        $this->assertSame(1, SellerOrderNote::query()->where('order_group_id', $group->id)->count());
        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertSee('Customer asked for a gift wrap');
        $this->actingAs($seller)->post(route('seller.orders.note', $group), ['body' => ''])->assertSessionHasErrors('body');

        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertDontSee('Customer asked for a gift wrap');
    }

    public function test_the_address_is_shown_only_while_something_is_left_to_ship_and_the_slip_follows_the_same_rule(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group] = $this->paidParcel($product);

        $page = $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertOk();
        $page->assertSee('Thandiwe Mokoena')->assertSee('14 Protea Lane')->assertSee('+27 82 555 0142');
        $this->actingAs($seller)->get(route('seller.orders.slip', $group))->assertOk()->assertSee('14 Protea Lane')->assertSee('Packing slip')->assertDontSee('R100.00');

        $this->shipParcel($group);
        $this->advanceAll($order->fresh(), OrderStatus::Delivered);

        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertOk()->assertDontSee('14 Protea Lane')->assertDontSee('+27 82 555 0142')->assertSee('Hidden');
        $this->actingAs($seller)->get(route('seller.orders.slip', $group))->assertOk()->assertDontSee('14 Protea Lane');
    }

    public function test_the_buyers_e_mail_is_never_shown_to_the_seller(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [$order, $group, , $buyer] = $this->paidParcel($product);

        foreach ([route('seller.orders.index'), route('seller.orders.show', $group), route('seller.orders.slip', $group)] as $url) {
            $this->actingAs($seller)->get($url)->assertDontSee($buyer->email);
        }
    }

    public function test_a_guest_order_appears_like_any_other_and_never_shows_the_guest_e_mail(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $variant = $product->variants()->first();
        $order = app(CheckoutService::class)->placeAsGuest('guest.buyer@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => '3 Guest Lane, Durban', 'shipping_name' => 'Gerda Guest'], (string) Str::uuid());
        $group = $order->items()->first()->group;

        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertSee($order->reference())->assertSee('Gerda Guest')->assertDontSee('guest.buyer@example.test');
        $this->actingAs($seller)->get(route('seller.orders.show', $group))->assertOk()->assertSee('3 Guest Lane')->assertDontSee('guest.buyer@example.test');
        $this->assertNotNull(app(GuestOrderAccess::class)->url($order));
    }

    public function test_the_list_costs_the_same_number_of_queries_for_two_parcels_as_for_twenty(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['stock' => 200]);
        $this->paidParcel($product);
        $this->paidParcel($product);
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk(); // a warm-up: the first request pays for the session and the person
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk();
        $few = count(\DB::getQueryLog());

        for ($i = 0; $i < 10; $i++) {
            $this->paidParcel($product);
        }
        \DB::flushQueryLog();
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk();
        $many = count(\DB::getQueryLog());

        $this->assertSame($few, $many, 'no query per parcel');
    }
}
