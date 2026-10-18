<?php

namespace Tests\Feature\Marketplace;

use App\Events\OrderPlaced;
use App\Listeners\NotifySellersOfNewOrder;
use App\Models\PromoCode;
use App\Notifications\NewOrderNotification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class OrderPlacedListenerTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_the_listener_is_registered_for_order_placed(): void
    {
        Event::fake();

        Event::assertListening(OrderPlaced::class, NotifySellersOfNewOrder::class);
    }

    public function test_each_seller_gets_exactly_one_notification_per_order_and_the_buyer_none(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        $buyer = $this->makeBuyer();

        $this->placeOrder($buyer, [
            [$this->makeListing($a), 1],
            [$this->makeListing($a), 2],
            [$this->makeListing($b), 1],
        ]);

        $this->assertSame(1, $a->notifications()->where('type', NewOrderNotification::class)->count());
        $this->assertSame(1, $b->notifications()->where('type', NewOrderNotification::class)->count());
        $this->assertSame(0, $buyer->notifications()->count());
    }

    public function test_the_notification_describes_only_that_sellers_share_and_never_the_order_total(): void
    {
        $a = $this->makeSeller(['name' => 'Alpha Copperworks Trading']);
        $b = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'TENPCT', 'discount_value' => 10]);

        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 200]), 1],
            [$this->makeListing($b, ['price' => 7777]), 1],
        ], ['promo_code' => 'TENPCT']);

        $data = $a->notifications()->first()->data;

        $this->assertSame($order->id, $data['order_id']);
        $this->assertStringContainsString('1 item worth $180.00', $data['message']);
        $this->assertStringNotContainsString('7,977', $data['message']);
        $this->assertStringNotContainsString('7,777', $data['message']);
        $this->assertSame(route('orders.show', $order), $data['url']);
    }

    public function test_no_notification_is_sent_when_checkout_fails(): void
    {
        $a = $this->makeSeller();
        $product = $this->makeListing($a, ['stock' => 1]);

        try {
            $this->placeOrder($this->makeBuyer(), [[$product, 5]]);
        } catch (\Throwable) {
            // expected: not enough stock
        }

        $this->assertSame(0, $a->notifications()->count());
    }

    public function test_the_event_is_dispatched_after_the_commit(): void
    {
        $this->assertInstanceOf(
            ShouldDispatchAfterCommit::class,
            new OrderPlaced($this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]])),
        );
    }
}
