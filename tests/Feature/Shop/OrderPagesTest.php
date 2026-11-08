<?php

namespace Tests\Feature\Shop;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\Notifications\OrderMessageNotification;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

class OrderPagesTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsShopFlows;
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller(['name' => 'Orders Test Stores']);
        $this->withDelivery($this->seller, ['price' => 60]);
        $this->buyer = $this->makeBuyer();
    }

    private function paidOrder(array $names = ['Waybill Kettle'], ?User $buyer = null): Order
    {
        $this->enablePaystack();
        $order = $this->placeOrder($buyer ?? $this->buyer, array_map(fn ($n) => [$this->makeListing($this->seller, ['name' => $n, 'price' => 100, 'stock' => 9]), 1], $names));

        return $this->payOrder($order, (string) random_int(1000000, 9999999999))->order->fresh();
    }

    private function refundsOk(): void
    {
        $this->fakeHttp(['api.paystack.co/refund' => \Illuminate\Support\Facades\Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7001, 'status' => 'pending']], 200)]);
    }

    private function ship(Order $order, array $data = ['carrier_key' => 'courier_guy', 'tracking_number' => 'TCG-12345678']): \App\Models\Shipment
    {
        return app(ShipmentService::class)->ship($order->groups()->first(), $this->seller, $data + ['estimated_from' => now()->addDays(2)->toDateString(), 'estimated_to' => now()->addDays(4)->toDateString()]);
    }

    private function guestOrder(string $email = 'guest@example.test'): Order
    {
        $this->enablePaystack();
        $variant = $this->makeListing($this->seller, ['name' => 'Guest Waybill Lamp', 'price' => 100, 'stock' => 9])->defaultVariant()->first();

        $order = app(CheckoutService::class)->placeAsGuest($email, [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => '1 Test Street, Cape Town', 'shipping_name' => 'Guest Person'], (string) Str::uuid());

        return $this->payOrder($order, (string) random_int(1000000, 9999999999))->order->fresh();
    }

    private function openGuest(Order $order): void
    {
        $this->get(route('guest.orders.access', ['order' => $order->id, 'token' => app(GuestOrderAccess::class)->tokenFor($order)]))->assertRedirect(route('guest.orders.show', $order));
    }

    // ---- the list ---------------------------------------------------------------------------------------------------------

    public function test_the_list_filters_by_status_finds_by_number_and_name_and_shows_only_my_orders(): void
    {
        $mine = $this->paidOrder(['Findable Teapot Alpha']);
        $this->ship($mine);
        $second = $this->paidOrder(['Other Findable Beta']);
        $someoneElses = $this->paidOrder(['Hidden Gamma Item'], $this->makeBuyer());

        $page = $this->actingAs($this->buyer)->get(route('orders.index'))->assertOk();
        $page->assertSee($mine->reference())->assertSee($second->reference())->assertDontSee($someoneElses->reference())->assertSee('Buy again');

        $this->actingAs($this->buyer)->get(route('orders.index', ['q' => 'Teapot']))->assertSee($mine->reference())->assertDontSee($second->reference());
        $this->actingAs($this->buyer)->get(route('orders.index', ['q' => $second->reference()]))->assertSee($second->reference())->assertDontSee($mine->reference());
        $this->actingAs($this->buyer)->get(route('orders.index', ['status' => 'cancelled']))->assertSee('No orders match');
        $this->actingAs($this->buyer)->get(route('orders.index', ['status' => 'in_progress']))->assertSee($mine->reference());

        $this->actingAs($this->makeBuyer())->get(route('orders.index'))->assertSee('No orders yet');
    }

    // ---- the buyer's order page --------------------------------------------------------------------------------------------

    public function test_the_order_page_shows_the_waybill_parcel_payment_totals_and_documents_from_real_data(): void
    {
        $order = $this->paidOrder(['Waybill Kettle']);
        $payment = $order->payments()->first();

        $page = $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertOk();
        $page->assertSee('Order progress')->assertSee('Order placed')->assertSee("Payment received")->assertSee("Not yet")
            ->assertSee('Parcel 1: Orders Test Stores')->assertSee('Waybill Kettle')
            ->assertSee('Estimated arrival:')->assertSee('Estimate from the seller')->assertSee('Not a promise', false)
            ->assertSee('Thandiwe Mokoena')->assertSee('14 Protea Lane')
            ->assertSee($payment->reference)->assertSee('Paystack')
            ->assertSee('R160.00')->assertSee('It is not a VAT tax invoice')->assertSee('Open the receipt')
            ->assertSee('Report a problem to the Emall team')->assertSee('Contact Orders Test Stores');
    }

    public function test_tracking_is_labelled_from_the_seller_and_linked_only_for_an_allowlisted_carrier(): void
    {
        $order = $this->paidOrder();
        $shipment = $this->ship($order, ['carrier_key' => 'courier_guy', 'tracking_number' => 'TCG-12345678', 'note' => 'Left the depot']);
        app(ShipmentService::class)->addEvent($shipment, $this->seller, 'Handed to the courier', 'Johannesburg');

        $page = $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertOk();
        $page->assertSee('Details from the seller')->assertSee('TCG-12345678')->assertSee('Track with The Courier Guy')
            ->assertSee('https://portal.thecourierguy.co.za/track?ref=TCG-12345678', false)->assertSee('rel="noopener noreferrer nofollow"', false)
            ->assertSee('Handed to the courier')->assertSee('These notes were typed by the seller. Dot.Emall does not check them with the carrier.')
            ->assertSee('Left the depot')->assertSee('Shipped')->assertSee('I have received it');

        $other = $this->paidOrder(['Off List Carrier Item']);
        $this->ship($other, ['carrier_name' => 'My cousin Sipho', 'tracking_number' => 'SIPHO-77']);
        $this->actingAs($this->buyer)->get(route('orders.show', $other))->assertSee('SIPHO-77')->assertSee('The seller did not give a carrier we can link to.')->assertDontSee('Track with My cousin Sipho');
    }

    public function test_a_step_without_a_date_says_not_yet_and_a_cancelled_order_ends_in_a_stop(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertSee('Not yet');

        $this->refundsOk();
        app(\App\Services\OrderLifecycle::class)->cancelItem($order->items()->first(), $this->buyer);
        $this->actingAs($this->buyer)->get(route('orders.show', $order->fresh()))->assertSee('Cancelled')->assertSee('Refund');
    }

    public function test_confirming_receipt_starts_the_return_window_and_unlocks_a_return(): void
    {
        $order = $this->paidOrder();
        $this->ship($order);
        $line = $order->items()->first();

        $this->actingAs($this->buyer)->post(route('orders.items.receive', [$order, $line]))->assertRedirect(route('orders.show', $order))->assertSessionHas('status');
        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);

        $page = $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertSee('Return this item')->assertSee('7 days from delivery');
        $this->actingAs($this->buyer)->get(route('orders.returns.create', [$order, $line]))->assertOk()->assertSee('Why are you returning it?');
    }

    public function test_a_line_that_is_not_shipped_cannot_be_confirmed_and_the_error_is_in_words(): void
    {
        $order = $this->paidOrder();
        $line = $order->items()->first();

        $this->actingAs($this->buyer)->post(route('orders.items.receive', [$order, $line]))->assertRedirect(route('orders.show', $order))->assertSessionHas('error');
        $this->assertSame(OrderStatus::Pending, $line->fresh()->status);
    }

    public function test_buyer_actions_are_for_the_buyer_alone(): void
    {
        $order = $this->paidOrder();
        $line = $order->items()->first();
        $stranger = $this->makeBuyer();

        foreach ([
            ['post', route('orders.reorder', $order)],
            ['post', route('orders.message', $order)],
            ['post', route('orders.report', $order)],
            ['post', route('orders.payment.check', $order)],
            ['post', route('orders.items.receive', [$order, $line])],
            ['get', route('orders.receipt', $order)],
            ['get', route('orders.returns.create', [$order, $line])],
        ] as [$verb, $url]) {
            $this->actingAs($stranger)->{$verb}($url)->assertNotFound();
            $this->actingAs($this->seller)->{$verb}($url)->assertNotFound();
        }

        $this->actingAs($stranger)->get(route('orders.show', $order))->assertForbidden();
    }

    public function test_the_seller_still_opens_the_same_address_to_their_own_trimmed_view(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->seller)->get(route('orders.show', $order))->assertOk()->assertViewIs('orders.show')->assertDontSee('Report a problem to the Emall team');
    }

    // ---- buy again ---------------------------------------------------------------------------------------------------------

    public function test_buy_again_puts_available_lines_in_the_cart_at_todays_price_and_names_what_it_left_out(): void
    {
        $order = $this->paidOrder(['Reorder Mug', 'Reorder Gone Lamp']);
        $mug = $order->items()->where('product_name', 'Reorder Mug')->first();
        $gone = $order->items()->where('product_name', 'Reorder Gone Lamp')->first();
        $mug->variant->update(['price' => 130]);
        $gone->variant->update(['stock' => 0]);

        $this->actingAs($this->buyer)->followingRedirects()->post(route('orders.reorder', $order))
            ->assertSee('Added 1 item to your cart at today')->assertSee('Left out "Reorder Gone Lamp"')->assertSee('R130.00');
    }

    // ---- messages ----------------------------------------------------------------------------------------------------------

    public function test_contacting_a_seller_stores_the_message_and_tells_the_sellers_people(): void
    {
        Notification::fake();
        $order = $this->paidOrder();
        $group = $order->groups()->first();

        $this->actingAs($this->buyer)->post(route('orders.message', $order), ['group' => $group->id, 'body' => 'Please can you confirm the colour is red?'])->assertRedirect(route('orders.show', $order))->assertSessionHas('status');

        $this->assertDatabaseHas('order_messages', ['order_id' => $order->id, 'order_group_id' => $group->id, 'kind' => 'seller', 'sender_email' => $this->buyer->email]);
        Notification::assertSentTo($this->seller, OrderMessageNotification::class);
        Notification::assertNotSentTo($this->buyer, OrderMessageNotification::class);

        $this->actingAs($this->buyer)->post(route('orders.message', $order), ['group' => $group->id, 'body' => 'short'])->assertRedirect()->assertSessionHasErrors('body', null, 'message');
        $this->actingAs($this->buyer)->post(route('orders.message', $order), ['group' => 99999, 'body' => 'A message for no one at all.'])->assertNotFound();
    }

    public function test_reporting_a_problem_goes_to_the_operators_and_is_limited_per_day(): void
    {
        Notification::fake();
        $operator = User::factory()->create(['is_platform_operator' => true]);
        $order = $this->paidOrder();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->buyer)->post(route('orders.report', $order), ['body' => "The parcel never came, report {$i}."])->assertSessionHas('status');
        }
        $this->actingAs($this->buyer)->post(route('orders.report', $order), ['body' => 'A sixth report in the same day.'])->assertSessionHasErrors('body', null, 'report');

        $this->assertSame(5, OrderMessage::where('kind', 'operator')->count());
        Notification::assertSentTo($operator, OrderMessageNotification::class);
        Notification::assertNotSentTo($this->seller, OrderMessageNotification::class);
    }

    public function test_the_receipt_is_a_receipt_and_not_a_tax_invoice(): void
    {
        $order = $this->paidOrder(['Receipt Kettle']);

        $this->actingAs($this->buyer)->get(route('orders.receipt', $order))->assertOk()->assertSee('Receipt for order '.$order->reference())
            ->assertSee('It is a receipt, not a VAT tax invoice')->assertSee('Receipt Kettle')->assertSee('R160.00')->assertSee('Print this receipt');
    }

    // ---- guests ------------------------------------------------------------------------------------------------------------

    public function test_a_guest_follows_the_link_and_sees_their_order_without_account_links_and_with_the_sign_in_prompt(): void
    {
        $order = $this->guestOrder();

        $this->get(route('guest.orders.show', $order))->assertNotFound();
        $this->openGuest($order);

        $page = $this->get(route('guest.orders.show', $order))->assertOk();
        $page->assertSee('You are viewing this order without an account')->assertSee('Sign in with InfoDot')->assertSee('Guest Waybill Lamp')->assertSee('Order progress')
            ->assertSee('Payment received')->assertDontSee(route('orders.index'), false)->assertDontSee('Address book')->assertDontSee('Move to wishlist');
    }

    public function test_a_wrong_rotated_or_account_owned_token_is_the_same_404_and_a_grant_opens_only_its_own_order(): void
    {
        $order = $this->guestOrder('one@example.test');
        $access = app(GuestOrderAccess::class);
        $old = $access->tokenFor($order);

        $this->get(route('guest.orders.access', ['order' => $order->id, 'token' => str_repeat('x', 43)]))->assertNotFound();
        $this->get(route('guest.orders.access', ['order' => 424242, 'token' => str_repeat('x', 43)]))->assertNotFound();

        $new = $access->rotate($order->fresh());
        $this->get(route('guest.orders.access', ['order' => $order->id, 'token' => $old]))->assertNotFound();
        $this->get(route('guest.orders.access', ['order' => $order->id, 'token' => $new]))->assertRedirect();

        // The grant is for this one order: another guest's order answers 404 for the same browser, on every page and action.
        $other = $this->guestOrder('two@example.test');
        $line = $other->items()->first();
        $this->get(route('guest.orders.show', $other))->assertNotFound();
        $this->get(route('guest.orders.receipt', $other))->assertNotFound();
        $this->post(route('guest.orders.items.receive', [$other, $line]))->assertNotFound();
        $this->post(route('guest.orders.reorder', $other))->assertNotFound();
        $this->get(route('guest.orders.show', $order))->assertOk();

        // An order that belongs to an account has no guest page at all.
        $account = $this->paidOrder();
        $this->get(route('guest.orders.show', $account))->assertNotFound();
    }

    public function test_a_guest_can_confirm_receipt_cancel_and_write_to_the_seller_from_their_page(): void
    {
        Notification::fake();
        $order = $this->guestOrder();
        $this->openGuest($order);
        $group = $order->groups()->first();
        $line = $order->items()->first();

        $this->post(route('guest.orders.message', $order), ['group' => $group->id, 'body' => 'Can I change the delivery date?'])->assertRedirect(route('guest.orders.show', $order))->assertSessionHas('status');
        $this->assertDatabaseHas('order_messages', ['order_id' => $order->id, 'sender_email' => 'guest@example.test']);

        $this->ship($order);
        $this->post(route('guest.orders.items.receive', [$order, $line]))->assertRedirect(route('guest.orders.show', $order));
        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);
        $this->get(route('guest.orders.show', $order))->assertSee('Return this item');
    }

    public function test_a_guest_can_cancel_an_unsent_item(): void
    {
        $order = $this->guestOrder();
        $this->openGuest($order);
        $line = $order->items()->first();
        $this->refundsOk();

        $this->post(route('guest.orders.items.cancel', [$order, $line]))->assertRedirect(route('guest.orders.show', $order))->assertSessionHas('status');
        $this->assertTrue($line->fresh()->isCancelled());
    }
}
