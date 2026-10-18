<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SellerDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class SellerPaymentGatingTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $seller;

    private $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
    }

    private function unpaid(): Order
    {
        return $this->enablePaystackAndPlace($this->buyer, [[$this->makeListing($this->seller, ['price' => 90, 'stock' => 9]), 1]]);
    }

    public function test_a_seller_cannot_move_a_line_of_an_unpaid_order_forward(): void
    {
        $order = $this->unpaid();
        $line = $order->items->first();

        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->actingAs($this->seller)
                ->patch(route('seller.order-items.update-status', $line), ['status' => $status])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame(OrderStatus::Pending, $line->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_seller_sees_awaiting_payment_and_no_ship_controls(): void
    {
        $order = $this->unpaid();
        $line = $order->items->first();

        $page = $this->actingAs($this->seller)->get(route('orders.show', $order));

        $page->assertOk()
            ->assertSee('Awaiting payment')
            ->assertSee('Do not ship')
            ->assertDontSee(route('seller.order-items.update-status', $line))
            ->assertDontSee('Mark Shipped')
            ->assertDontSee('Mark Processing');
        // the buyer's address is not released before payment
        $page->assertDontSee('14 Protea Lane, Parkhurst');

        $this->actingAs($this->seller)->get(route('seller.orders.index'))
            ->assertSee('awaiting payment');
    }

    public function test_after_payment_the_seller_can_fulfil_and_sees_the_address(): void
    {
        $order = $this->unpaid();
        $line = $order->items->first();
        $this->payOrder($order);

        $page = $this->actingAs($this->seller)->get(route('orders.show', $order));
        $page->assertDontSee('Awaiting payment')
            ->assertSee(route('seller.order-items.update-status', $line))
            ->assertSee('14 Protea Lane, Parkhurst')
            ->assertSee('earned, not paid out');

        $this->actingAs($this->seller)
            ->patch(route('seller.order-items.update-status', $line), ['status' => 'processing'])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Processing, $line->fresh()->status);
    }

    public function test_the_seller_page_never_reveals_payment_details(): void
    {
        $order = $this->unpaid();
        $payment = $this->payOrder($order);

        $this->actingAs($this->seller)->get(route('orders.show', $order))
            ->assertDontSee($payment->reference)
            ->assertDontSee('Paystack');
    }

    public function test_a_seller_can_still_cancel_an_unpaid_line(): void
    {
        $order = $this->unpaid();

        $this->actingAs($this->seller)->post(route('order-items.cancel', $order->items->first()))->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_orders_placed_without_paystack_keep_the_old_behaviour(): void
    {
        config(['paystack.secret_key' => '']);
        $order = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller, ['stock' => 5]), 1]]);
        $line = $order->items->first();

        $this->actingAs($this->seller)->get(route('orders.show', $order))
            ->assertDontSee('Awaiting payment')
            ->assertSee(route('seller.order-items.update-status', $line));
        $this->actingAs($this->seller)
            ->patch(route('seller.order-items.update-status', $line), ['status' => 'processing'])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Processing, $line->fresh()->status);
    }

    public function test_the_seller_is_notified_when_the_order_is_paid_not_before(): void
    {
        $order = $this->unpaid();
        $this->assertCount(0, $this->seller->fresh()->notifications);

        $this->payOrder($order);

        $notes = $this->seller->fresh()->notifications;
        $this->assertCount(1, $notes);
        $this->assertSame('new_order', $notes->first()->data['type']);
        $this->assertStringNotContainsString('unpaid', $notes->first()->data['message']);
    }

    public function test_dashboard_revenue_counts_only_paid_or_legacy_orders_and_awaiting_counts_only_payable_ones(): void
    {
        $unpaid = $this->unpaid();               // 90.00, awaiting payment
        $paid = $this->enablePaystackAndPlace($this->buyer, [[$this->makeListing($this->seller, ['price' => 20]), 1]]);
        $this->payOrder($paid);
        config(['paystack.secret_key' => '']);
        $legacy = $this->placeOrder($this->buyer, [[$this->makeListing($this->seller, ['price' => 7]), 1]]);

        // force every line to delivered (the unpaid one can only get there through bad data)
        OrderItem::query()->update(['status' => OrderStatus::Delivered->value]);

        $summary = app(SellerDashboard::class)->summary($this->seller);

        $this->assertSame(2000 + 700, $summary['revenueCents'], 'the unpaid 90.00 is not revenue');

        OrderItem::query()->update(['status' => OrderStatus::Pending->value]);
        $summary = app(SellerDashboard::class)->summary($this->seller);
        $this->assertSame(2, $summary['awaitingFulfilment'], 'paid + legacy; the unpaid order is not waiting on the seller');
        $this->assertSame(1, $summary['awaitingPayment']);
    }

    public function test_the_dashboard_says_plainly_that_payouts_do_not_exist_yet(): void
    {
        $this->payOrder($this->unpaid());

        $this->actingAs($this->seller)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Payout is not available yet')
            ->assertSee('earned, not paid out');
    }
}
