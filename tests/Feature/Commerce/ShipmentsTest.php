<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Shipment;
use App\Services\Shipping\Carriers;
use App\Services\Shipping\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class ShipmentsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function service(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    private function parcel(int $lines = 2): array
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, array_map(fn () => [$this->makeListing($seller), 1], range(1, $lines)));

        return [$seller, $buyer, $order, $order->groups()->first()];
    }

    public function test_shipping_a_parcel_records_what_the_seller_entered_and_moves_every_line_to_shipped(): void
    {
        [$seller, , $order, $group] = $this->parcel();

        $shipment = $this->service()->ship($group, $seller, [
            'carrier_key' => 'courier_guy', 'tracking_number' => 'TCG-12345678', 'estimated_from' => '2026-11-12', 'estimated_to' => '2026-11-14', 'note' => 'Left with the depot',
        ]);

        $this->assertSame('The Courier Guy', $shipment->carrier_name);
        $this->assertSame('TCG-12345678', $shipment->tracking_number);
        $this->assertSame('https://portal.thecourierguy.co.za/track?ref=TCG-12345678', $shipment->tracking_url);
        $this->assertSame('2026-11-14', $shipment->estimated_to->toDateString());
        $this->assertSame(2, $shipment->items()->count());
        $this->assertSame([OrderStatus::Shipped, OrderStatus::Shipped], $order->items()->orderBy('id')->get()->pluck('status')->all());
        $this->assertNotNull($shipment->shipped_at);
        $this->assertSame('shipped', $group->fresh()->status);
    }

    public function test_a_carrier_off_the_allowlist_is_plain_text_with_no_link(): void
    {
        [$seller, , , $group] = $this->parcel(1);

        $shipment = $this->service()->ship($group, $seller, ['carrier_name' => 'My cousin Sipho', 'tracking_number' => 'SIPHO-77']);

        $this->assertSame('My cousin Sipho', $shipment->carrier_name);
        $this->assertSame('SIPHO-77', $shipment->tracking_number);
        $this->assertNull($shipment->tracking_url);
        $this->assertNull($shipment->carrier_key);
    }

    public function test_a_known_carrier_with_a_number_of_the_wrong_shape_gets_no_link(): void
    {
        $carriers = app(Carriers::class);

        $this->assertNull($carriers->trackingUrl('aramex', 'not-numeric'));
        $this->assertNull($carriers->trackingUrl('aramex', '12'));
        $this->assertNull($carriers->trackingUrl('nonexistent', '123456'));
        $this->assertNull($carriers->trackingUrl(null, '123456'));
        $this->assertNull($carriers->trackingUrl('courier_guy', "ABC123\"><script>"));
        $this->assertNull($carriers->trackingUrl('courier_guy', '../../etc'));
        $this->assertStringStartsWith('https://www.aramex.com/', $carriers->trackingUrl('aramex', '1234567890'));
    }

    public function test_every_configured_carrier_link_is_https_to_a_fixed_host(): void
    {
        foreach (config('emall.shipping.carriers') as $key => $carrier) {
            $this->assertStringStartsWith('https://', $carrier['url'], $key);
            $this->assertStringContainsString('{n}', $carrier['url'], $key);
            $this->assertNotNull(parse_url(str_replace('{n}', '1', $carrier['url']), PHP_URL_HOST), $key);
        }

        $this->assertEqualsCanonicalizing(['courier_guy', 'aramex', 'dsv', 'pargo', 'pudo', 'fastway', 'post_office'], array_keys(config('emall.shipping.carriers')));
    }

    public function test_a_tracking_number_with_odd_characters_is_refused(): void
    {
        [$seller, , , $group] = $this->parcel(1);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->ship($group, $seller, ['carrier_key' => 'dsv', 'tracking_number' => '<b>x</b>']);
    }

    public function test_an_unknown_carrier_key_is_refused(): void
    {
        [$seller, , , $group] = $this->parcel(1);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->ship($group, $seller, ['carrier_key' => 'evil_corp']);
    }

    public function test_another_seller_cannot_ship_my_parcel(): void
    {
        [, , , $group] = $this->parcel(1);

        $this->expectException(AuthorizationException::class);
        $this->service()->ship($group, $this->makeSeller());
    }

    public function test_an_unpaid_order_cannot_be_shipped_even_with_a_shipment(): void
    {
        $this->enablePaystack();
        [$seller, , $order, $group] = $this->parcel(1);

        try {
            $this->service()->ship($group, $seller, ['tracking_number' => 'ABC12345']);
            $this->fail('an unpaid order cannot ship');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('awaiting payment', $e->getMessage());
        }

        $this->assertSame(0, Shipment::query()->count(), 'the failed attempt left no shipment behind');
        $this->assertSame(OrderStatus::Pending, $order->items()->first()->status);

        $this->payOrder($order);
        $this->service()->ship($group->fresh(), $seller, ['tracking_number' => 'ABC12345']);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_a_collection_parcel_cannot_be_shipped(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller, ['type' => 'collection', 'price' => 0, 'collection_address' => 'Depot']);
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->ship($order->groups()->first(), $seller);
    }

    public function test_part_of_a_parcel_can_ship_first_and_the_rest_later(): void
    {
        [$seller, , $order, $group] = $this->parcel(2);
        [$a, $b] = $order->items->all();

        $first = $this->service()->ship($group, $seller, ['order_item_ids' => [$a->id], 'tracking_number' => 'FIRST-001']);
        $second = $this->service()->ship($group->fresh(), $seller, ['tracking_number' => 'SECOND-002']);

        $this->assertSame([$a->id], $first->items()->pluck('order_items.id')->all());
        $this->assertSame([$b->id], $second->items()->pluck('order_items.id')->all());

        $this->expectException(InvalidArgumentException::class);
        $this->service()->ship($group->fresh(), $seller);
    }

    public function test_events_are_text_the_seller_types_and_nothing_else(): void
    {
        [$seller, , , $group] = $this->parcel(1);
        $shipment = $this->service()->ship($group, $seller, ['tracking_number' => 'TRK-0001']);

        $this->service()->addEvent($shipment, $seller, 'Out for delivery', 'Cape Town');
        $this->service()->addEvent($shipment, $seller, 'Collected by courier', null, now()->subDay());

        $events = $shipment->fresh()->events;
        $this->assertSame(['Collected by courier', 'Out for delivery'], $events->pluck('description')->all(), 'ordered by the time the seller gave');
        $this->assertSame($seller->id, $events->first()->created_by);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->addEvent($shipment, $seller, str_repeat('x', 191));
    }

    public function test_only_the_seller_adds_events_and_the_buyer_and_operator_can_read_them(): void
    {
        [$seller, $buyer, , $group] = $this->parcel(1);
        $shipment = $this->service()->ship($group, $seller);
        $operator = \App\Models\User::factory()->create(['is_platform_operator' => true]);
        $stranger = $this->makeBuyer();

        $this->assertTrue(Gate::forUser($buyer)->allows('view', $shipment));
        $this->assertTrue(Gate::forUser($operator)->allows('view', $shipment));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $shipment));
        $this->assertTrue(Gate::forUser($seller)->allows('manage', $shipment));
        $this->assertFalse(Gate::forUser($buyer)->allows('manage', $shipment));

        $this->expectException(AuthorizationException::class);
        $this->service()->addEvent($shipment, $stranger, 'I am not the seller');
    }

    public function test_the_buyer_is_told_once_per_shipment_with_the_carrier_and_tracking_number(): void
    {
        [$seller, $buyer, , $group] = $this->parcel(2);
        $buyer->notifications()->delete();

        $this->service()->ship($group, $seller, ['carrier_key' => 'pargo', 'tracking_number' => 'PRG-99887']);

        $notices = $buyer->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'order_shipped');
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('Pargo', $notices->first()->data['message']);
        $this->assertStringContainsString('PRG-99887', $notices->first()->data['message']);
        $this->assertStringContainsString('2 items', $notices->first()->data['title']);
        $this->assertStringContainsString('we do not check them with the carrier', $notices->first()->data['message']);
    }
}
