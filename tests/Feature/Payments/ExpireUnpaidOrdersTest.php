<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PromoCode;
use App\Services\OrderLifecycle;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class ExpireUnpaidOrdersTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private $seller;

    private $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(int $stock = 10, int $qty = 3): array
    {
        $listing = $this->makeListing($this->seller, ['price' => 50, 'stock' => $stock]);

        return [$listing, $this->enablePaystackAndPlace($this->buyer, [[$listing, $qty]])];
    }

    public function test_an_unpaid_order_past_the_window_is_cancelled_restocked_and_the_buyer_told(): void
    {
        [$listing, $order] = $this->order();
        $this->assertSame(7, $listing->fresh()->stock);

        Carbon::setTestNow('2026-10-18 11:01:00');
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(10, $listing->fresh()->stock);
        $this->assertTrue($order->items->every(fn ($l) => $l->status === OrderStatus::Cancelled));
        $notes = $this->buyer->fresh()->notifications;
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('was not paid', $notes->first()->data['message']);
        $this->assertStringContainsString($order->reference(), $notes->first()->data['message']);
    }

    public function test_an_order_inside_the_window_is_left_alone_even_a_second_before(): void
    {
        [$listing, $order] = $this->order();

        Carbon::setTestNow('2026-10-18 10:59:59');
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(7, $listing->fresh()->stock);
    }

    public function test_the_window_is_configurable(): void
    {
        [, $order] = $this->order();
        config(['paystack.unpaid_window_minutes' => 15]);

        Carbon::setTestNow('2026-10-18 10:16:00');
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_the_promo_use_goes_back_through_the_existing_lifecycle(): void
    {
        $listing = $this->makeListing($this->seller, ['price' => 50, 'stock' => 10]);
        $promo = PromoCode::factory()->create(['store_id' => $this->seller->store->id, 'code' => 'SAVE5', 'discount_type' => 'fixed', 'discount_value' => 5, 'used_count' => 0]);
        $this->enablePaystack();
        $order = $this->placeOrder($this->buyer, [[$listing, 1]], ['promo_code' => 'SAVE5']);
        $this->assertSame(1, $promo->fresh()->used_count);

        Carbon::setTestNow('2026-10-18 11:30:00');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(0, $promo->fresh()->used_count);
        $this->assertNotNull($order->fresh()->promo_released_at);
    }

    public function test_running_it_again_is_a_no_op_no_second_restock_no_second_notification(): void
    {
        [$listing, $order] = $this->order();

        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->artisan('orders:expire-unpaid');
        $this->artisan('orders:expire-unpaid');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(10, $listing->fresh()->stock);
        $this->assertCount(1, $this->buyer->fresh()->notifications);
    }

    public function test_a_paid_order_is_never_auto_cancelled(): void
    {
        [$listing, $order] = $this->order();
        $this->payOrder($order);

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(7, $listing->fresh()->stock);
    }

    public function test_orders_placed_without_paystack_are_never_auto_cancelled(): void
    {
        config(['paystack.secret_key' => '']);
        $listing = $this->makeListing($this->seller, ['stock' => 4]);
        $order = $this->placeOrder($this->buyer, [[$listing, 1]]);

        Carbon::setTestNow('2026-12-18 10:00:00');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(3, $listing->fresh()->stock);
    }

    public function test_a_payment_that_lands_between_selection_and_cancellation_wins(): void
    {
        [, $order] = $this->order();
        $stale = Order::findOrFail($order->id); // what the command selected: still unpaid
        $this->payOrder($order);

        Carbon::setTestNow('2026-10-18 12:00:00');
        $cancelled = app(OrderLifecycle::class)->cancelUnpaidOrder($stale);

        $this->assertFalse($cancelled);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_open_attempts_are_marked_abandoned_when_the_order_expires(): void
    {
        [, $order] = $this->order();
        $attempt = $this->startAttempt($order);

        Carbon::setTestNow('2026-10-18 12:00:00');
        // the sweep asks Paystack about open attempts before giving up on the order
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents, 'abandoned')]);
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(PaymentAttemptStatus::Abandoned, $attempt->fresh()->status);
    }

    public function test_a_buyer_who_paid_at_paystack_a_moment_ago_is_not_cancelled_by_the_sweep(): void
    {
        [$listing, $order] = $this->order();
        $attempt = $this->startAttempt($order);

        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents)]);
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(7, $listing->fresh()->stock);
        $this->assertCount(0, $this->buyer->fresh()->notifications);
    }

    public function test_failed_payment_orders_expire_too(): void
    {
        [, $order] = $this->order();
        $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();

        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->artisan('orders:expire-unpaid');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_the_command_is_scheduled_every_ten_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'orders:expire-unpaid'));

        $this->assertCount(1, $events);
        $this->assertSame('*/10 * * * *', $events->first()->expression);
    }
}
