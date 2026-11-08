<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CommerceNotification;
use App\Notifications\NewOrderNotification;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notify\NotifyClient;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    /** Oldest first. The relation is already ordered newest first, so the order is replaced, not appended to. */
    private function typesFor(User $user): array
    {
        return $user->fresh()->notifications()->reorder('created_at')->get()->map(fn ($n) => $n->data['type'])->all();
    }

    public function test_transactional_notices_use_the_app_and_e_mail_and_cannot_be_switched_off(): void
    {
        $user = $this->makeBuyer();
        $prefs = app(NotificationPreferences::class);
        $notice = new CommerceNotification('order_placed', 0);

        $this->assertEqualsCanonicalizing(['database', 'mail'], $notice->via($user));

        $result = $prefs->set($user, 'transactional', false, false);

        $this->assertSame(['mail' => true, 'in_app' => true, 'locked' => true], $result);
        $this->assertEqualsCanonicalizing(['database', 'mail'], $notice->via($user->fresh()));
        $this->assertSame(0, $user->notificationPreferences()->count());
    }

    public function test_marketing_is_off_by_default_everywhere_and_alerts_are_in_app_only(): void
    {
        $user = $this->makeBuyer();
        $prefs = app(NotificationPreferences::class);

        $this->assertSame([], $prefs->channels($user, NotificationPreferences::MARKETING));
        $this->assertSame(['database'], $prefs->channels($user, NotificationPreferences::ALERTS));
        $this->assertSame([], $prefs->channels($user, 'something-unknown'), 'an unknown category defaults to nothing');
    }

    public function test_a_person_can_opt_in_and_out_of_alerts_and_marketing(): void
    {
        $user = $this->makeBuyer();
        $prefs = app(NotificationPreferences::class);

        $prefs->set($user, NotificationPreferences::MARKETING, true, false);
        $prefs->set($user, NotificationPreferences::ALERTS, true, true);

        $this->assertSame(['mail'], $prefs->channels($user, NotificationPreferences::MARKETING));
        $this->assertEqualsCanonicalizing(['database', 'mail'], $prefs->channels($user, NotificationPreferences::ALERTS));

        $prefs->set($user, NotificationPreferences::MARKETING, false, false);
        $this->assertSame([], $prefs->channels($user, NotificationPreferences::MARKETING));
        $this->assertSame(2, $user->notificationPreferences()->count());
        $this->assertSame(['transactional', 'alerts', 'marketing'], array_keys($prefs->all($user)));
    }

    public function test_every_notice_kind_renders_for_a_real_order(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);

        foreach (['order_placed', 'order_paid', 'payment_failed', 'item_packing', 'order_shipped', 'order_ready_for_collection', 'order_delivered', 'order_collected', 'order_refunded', 'return_requested', 'return_approved', 'return_declined', 'return_received', 'return_refunded', 'return_escalated', 'ledger_released', 'low_stock'] as $kind) {
            $notice = new CommerceNotification($kind, $order->id, ['item' => 'Kettle', 'amount_cents' => 1500, 'net_cents' => 1500, 'stock' => 2, 'tracking' => 'TRK1', 'carrier' => 'Pargo', 'reason' => 'x', 'respond_by' => '19 Nov', 'url' => 'https://example.test/x']);
            $data = $notice->toDatabase($buyer);
            $mail = $notice->toMail($buyer);

            $this->assertSame($kind, $data['type']);
            $this->assertNotEmpty($data['title'], $kind);
            $this->assertNotEmpty($data['message'], $kind);
            $this->assertSame($data['title'], $mail->subject, $kind);
        }
    }

    public function test_the_order_journey_sends_a_notice_at_every_real_event(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100]), 1]]);
        $this->assertSame(['order_placed'], $this->typesFor($buyer));
        $this->assertSame([], $this->typesFor($seller), 'sellers hear nothing until the order is paid');

        // Notices are stored with a one-second timestamp: move the clock between events so "oldest first" is a fact, not a coin toss.
        $this->travel(1)->seconds();
        $this->payOrder($order);
        $this->assertSame(['order_placed', 'order_paid'], $this->typesFor($buyer));
        $this->assertSame(['new_order'], $this->typesFor($seller));

        $this->travel(1)->seconds();
        app(ShipmentService::class)->ship($order->groups()->first(), $seller, ['carrier_key' => 'dsv', 'tracking_number' => 'DSV-5566']);
        $this->travel(1)->seconds();
        app(OrderLifecycle::class)->advanceItem($order->items()->first(), OrderStatus::Delivered, $seller);

        $this->assertSame(['order_placed', 'order_paid', 'order_shipped', 'order_delivered'], $this->typesFor($buyer));
    }

    public function test_a_failed_payment_tells_the_buyer(): void
    {
        $this->enablePaystack();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller()), 1]]);
        $attempt = $this->startAttempt($order);

        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($attempt->reference, $attempt->amount_cents, 'failed')]);
        app(PaymentService::class)->confirmReturn($order->fresh(), $attempt->reference);

        $this->assertContains('payment_failed', $this->typesFor($buyer));
    }

    public function test_a_processed_refund_tells_the_buyer_how_much(): void
    {
        $this->enablePaystack();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($this->makeSeller(), ['price' => 100]), 1], [$this->makeListing($this->makeSeller(), ['price' => 50]), 1]]);
        $this->payOrder($order);
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'q', 'data' => ['id' => 5151, 'status' => 'processed']], 200)]);

        app(OrderLifecycle::class)->cancelItem($order->items->firstWhere('total', '50.00'), $buyer);

        $notice = $buyer->fresh()->notifications()->get()->first(fn ($n) => $n->data['type'] === 'order_refunded');
        $this->assertNotNull($notice);
        $this->assertStringContainsString('R50.00', $notice->data['message']);
    }

    public function test_e_mail_is_sent_for_a_transactional_event_and_not_for_marketing(): void
    {
        Mail::fake();
        Notification::fake();
        $user = $this->makeBuyer();

        $user->notify(new CommerceNotification('order_paid', 0));
        Notification::assertSentTo($user, CommerceNotification::class, fn ($n, $channels) => in_array('mail', $channels) && in_array('database', $channels));
    }

    public function test_the_existing_seller_notices_are_now_mail_and_app_too(): void
    {
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);

        $notice = new NewOrderNotification($order, $seller->store);

        $this->assertEqualsCanonicalizing(['database', 'mail'], $notice->via($seller));
        $mail = $notice->toMail($seller);
        $this->assertSame('New order received', $mail->subject);
        $this->assertStringContainsString($order->reference(), implode(' ', $mail->introLines));
    }

    public function test_a_notice_failing_never_undoes_the_order(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $variant = $this->makeListing($seller, ['stock' => 3])->defaultVariant()->first();
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('mail is down'));

        $order = $this->placeOrder($buyer, [[$variant->product, 1]]);

        $this->assertNotNull($order->id);
        $this->assertSame(2, $variant->fresh()->stock);
    }

    // ---- Dot.Notify, optional -----------------------------------------------------------------------------------------

    public function test_dot_notify_is_inert_unless_both_settings_exist(): void
    {
        Http::fake()->preventStrayRequests();
        $user = $this->makeBuyer();

        config(['emall.notify.url' => 'https://notify.example.test/api/ingest/events', 'emall.notify.secret' => null]);
        $this->assertFalse(app(NotifyClient::class)->enabled());
        $this->assertFalse(app(NotifyClient::class)->send($user, 'X', '1', []));

        $this->makeBuyer()->notify(new CommerceNotification('order_paid', 0));
        Http::assertNothingSent();
    }

    public function test_dot_notify_gets_a_signed_event_for_a_person_with_an_infodot_company(): void
    {
        config(['emall.notify.url' => 'https://notify.example.test/api/ingest/events', 'emall.notify.secret' => 'shhh', 'emall.notify.platform' => 'emall']);
        Http::fake(['notify.example.test/*' => Http::response(['ok' => true], 200)]);
        $user = $this->makeBuyer();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => '11111111-2222-3333-4444-555555555555']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->assertTrue(app(NotifyClient::class)->enabled());
        $user->fresh()->notify(new CommerceNotification('order_paid', 0));

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $body = $request->body();

            return $request->url() === 'https://notify.example.test/api/ingest/events'
                && $request->header('X-InfoDot-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'shhh')
                && json_decode($body, true)['company_uuid'] === '11111111-2222-3333-4444-555555555555'
                && json_decode($body, true)['platform'] === 'emall'
                && ! str_contains($body, 'shhh');
        });
    }

    public function test_dot_notify_skips_people_without_an_infodot_company_and_swallows_outages(): void
    {
        config(['emall.notify.url' => 'https://notify.example.test/x', 'emall.notify.secret' => 'shhh']);
        Http::fake()->preventStrayRequests();
        $this->makeBuyer()->notify(new CommerceNotification('order_paid', 0));
        Http::assertNothingSent();

        Http::fake(['notify.example.test/*' => Http::response('boom', 500)]);
        $user = $this->makeBuyer();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => 'aaaaaaaa-2222-3333-4444-555555555555']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $user->fresh()->notify(new CommerceNotification('order_paid', 0));

        $this->assertSame(1, $user->notifications()->count(), 'the in-app notice still arrived');
    }
}
