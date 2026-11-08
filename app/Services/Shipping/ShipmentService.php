<?php

namespace App\Services\Shipping;

use App\Enums\OrderStatus;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Notifications\OrderNotifier;
use App\Services\OrderLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shipping a seller group (D-5, plan 8). The seller says what they sent and how: carrier, tracking number, their own
 * delivery estimate, and later any events in their own words. Everything stored is labelled "from the seller" by the UI;
 * nothing is fetched from a carrier and nothing is invented.
 *
 * ship() creates the shipment, attaches the lines it carries, and moves those lines to shipped through OrderLifecycle (so
 * a shipment on an unpaid order, a collection parcel or a cancelled line is refused by the state machine, not here).
 */
class ShipmentService
{
    public function __construct(
        private readonly OrderLifecycle $lifecycle,
        private readonly Carriers $carriers,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * @param  array{carrier_key?: ?string, carrier_name?: ?string, tracking_number?: ?string, estimated_from?: ?string, estimated_to?: ?string, note?: ?string, order_item_ids?: array<int, int>}  $data
     *
     * @throws AuthorizationException when the user does not own the group
     * @throws ValidationException
     * @throws \InvalidArgumentException when the order cannot be shipped yet
     */
    public function ship(OrderGroup $group, User $seller, array $data = []): Shipment
    {
        if ($group->seller_id === null || $group->seller_id !== $seller->id) {
            throw new AuthorizationException('This parcel belongs to another seller.');
        }

        $tracking = $this->tracking($data);

        $shipment = DB::transaction(function () use ($group, $seller, $data, $tracking) {
            $lines = OrderItem::query()->where('order_group_id', $group->id)
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
                ->when(! empty($data['order_item_ids']), fn ($q) => $q->whereIn('id', $data['order_item_ids']))
                ->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw new \InvalidArgumentException('There is nothing in this parcel left to ship.');
            }

            $shipment = Shipment::create(array_merge($tracking, [
                'order_group_id' => $group->id,
                'order_id' => $group->order_id,
                'seller_id' => $seller->id,
                'shipped_at' => now(),
                'estimated_from' => $this->date($data['estimated_from'] ?? null),
                'estimated_to' => $this->date($data['estimated_to'] ?? null),
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 255) : null,
            ]));

            $shipment->items()->attach($lines->pluck('id'));

            foreach ($lines as $line) {
                $this->lifecycle->advanceItem($line, OrderStatus::Shipped, $seller, 'Shipment #'.$shipment->id, 'seller', notify: false);
            }

            return $shipment;
        });

        // One notice per shipment, however many lines it carries.
        $first = $shipment->items()->with('order')->first();
        $count = $shipment->items()->count();

        if ($first !== null) {
            $this->notifier->itemMoved($first->setAttribute('product_name', $count > 1 ? "{$count} items" : $first->product_name)->setAttribute('variant_label', $count > 1 ? null : $first->variant_label), 'shipped', $shipment);
        }

        return $shipment;
    }

    /**
     * Updates the carrier details on a shipment the seller already created.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTracking(Shipment $shipment, User $seller, array $data): Shipment
    {
        $this->assertOwns($shipment, $seller);
        $shipment->update($this->tracking($data));

        return $shipment->refresh();
    }

    /**
     * A line of text from the seller about the parcel ("Collected by courier", "Out for delivery"). Order is by the time
     * they give; it defaults to now.
     */
    public function addEvent(Shipment $shipment, User $seller, string $description, ?string $location = null, ?Carbon $at = null): ShipmentEvent
    {
        $this->assertOwns($shipment, $seller);

        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 190) {
            throw ValidationException::withMessages(['description' => 'Write a short update (up to 190 characters).']);
        }

        return ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'description' => $description,
            'location' => $location !== null ? mb_substr(trim($location), 0, 120) ?: null : null,
            'occurred_at' => $at ?? now(),
            'created_by' => $seller->id,
        ]);
    }

    /**
     * Carrier and tracking number as the seller gave them. A carrier on the allowlist gets its name and a tracking link
     * (only if the number looks like that carrier's); any other carrier is kept as plain text without a link.
     *
     * @param  array<string, mixed>  $data
     * @return array{carrier_key: ?string, carrier_name: ?string, tracking_number: ?string, tracking_url: ?string}
     */
    public function tracking(array $data): array
    {
        $key = isset($data['carrier_key']) && $data['carrier_key'] !== '' ? (string) $data['carrier_key'] : null;
        $number = isset($data['tracking_number']) ? trim((string) $data['tracking_number']) : null;
        $number = $number === '' ? null : $number;

        if ($key !== null && $this->carriers->name($key) === null) {
            throw ValidationException::withMessages(['carrier_key' => 'Choose a carrier from the list, or type its name.']);
        }

        if ($number !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_. \/]{2,79}$/', $number)) {
            throw ValidationException::withMessages(['tracking_number' => 'A tracking number uses letters, numbers and dashes (3 to 80 characters).']);
        }

        $name = $key !== null ? $this->carriers->name($key) : (isset($data['carrier_name']) ? mb_substr(trim((string) $data['carrier_name']), 0, 80) : null);

        return [
            'carrier_key' => $key,
            'carrier_name' => $name !== '' ? $name : null,
            'tracking_number' => $number,
            'tracking_url' => $this->carriers->trackingUrl($key, $number),
        ];
    }

    private function assertOwns(Shipment $shipment, User $seller): void
    {
        if ($shipment->seller_id === null || $shipment->seller_id !== $seller->id) {
            throw new AuthorizationException('This shipment belongs to another seller.');
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['estimated_to' => 'Enter a valid date.']);
        }
    }
}
