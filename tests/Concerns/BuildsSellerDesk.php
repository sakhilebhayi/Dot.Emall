<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Returns\ReturnService;

/**
 * Fixtures for the seller desk tests (R-43 W6): a seller with a delivery method, a PAID parcel (real checkout, real payment path,
 * so the ledger has its sale), and helpers that ship, deliver and return through the real services.
 * Needs BuildsMarketplace, BuildsCommerce and BuildsPayments on the same class.
 */
trait BuildsSellerDesk
{
    /** A seller whose store ships (R60 courier, 1 day to pack) and has one listing at R100 with 10 in stock. */
    protected function shippingSeller(array $store = [], array $product = []): array
    {
        $seller = $this->makeSeller($store);
        $this->withDelivery($seller, ['price' => 60]);

        return [$seller, $this->makeListing($seller, array_merge(['name' => 'Ledger Test Kettle '.uniqid(), 'price' => 100, 'stock' => 10], $product))];
    }

    /**
     * A paid order of one line of $product. Returns [order, group, line, buyer].
     *
     * @return array{0: Order, 1: OrderGroup, 2: OrderItem, 3: User}
     */
    protected function paidParcel(Product $product, ?User $buyer = null, int $quantity = 1): array
    {
        $buyer ??= $this->makeBuyer();
        $this->enablePaystack();
        $order = $this->placeOrder($buyer, [[$product, $quantity]]);
        $this->payOrder($order, (string) random_int(1_000_000_000, 9_999_999_999)); // each payment has its own gateway id
        $order = $order->fresh();
        $line = $order->items()->orderBy('id')->first();

        return [$order, $line->group, $line, $buyer];
    }

    /** Ships the parcel through the real ShipmentService (a shipment with a carrier and a tracking number). */
    protected function shipParcel(OrderGroup $group, array $data = []): \App\Models\Shipment
    {
        return app(\App\Services\Shipping\ShipmentService::class)->ship($group->fresh(), \App\Models\User::find($group->seller_id), array_merge(['carrier_key' => 'courier_guy', 'tracking_number' => 'TCG123456'], $data));
    }

    /** Ships and delivers every line of the order through the state machine, as its seller. */
    protected function shipAndDeliver(Order $order): Order
    {
        return $this->deliver($order);
    }

    /** A return the buyer asked for on a delivered line. */
    protected function returnFor(OrderItem $line, User $buyer, string $reason = 'faulty', ?string $note = 'It does not work'): ReturnRequest
    {
        return app(ReturnService::class)->request($line->fresh(), $buyer, $reason, $note);
    }

    protected function lineStatus(OrderItem $line): OrderStatus
    {
        return $line->fresh()->status;
    }

    /** Someone who belongs to the owner's team and owns no store: a colleague. */
    protected function colleagueOf(User $owner): User
    {
        $colleague = $this->makeBuyer();
        $team = $owner->personalTeam();
        $team->users()->attach($colleague, ['role' => 'editor']);
        $colleague->switchTeam($team);

        return $colleague->fresh();
    }

    protected function operator(): User
    {
        $operator = $this->makeBuyer();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator->fresh();
    }
}
