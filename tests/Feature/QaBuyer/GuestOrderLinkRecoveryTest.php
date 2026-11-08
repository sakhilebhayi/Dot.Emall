<?php

namespace Tests\Feature\QaBuyer;

use App\Models\Order;
use App\Notifications\CommerceNotification;
use App\Services\Cart\CartManager;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/** A guest who lost the order e-mail, or whose link expired, can ask for it again without the form revealing who has shopped here (D-140). */
class GuestOrderLinkRecoveryTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function guestOrder(string $email = 'lost.link@example.test'): Order
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 80, 'stock' => 5]);
        app(CartManager::class)->add(null, $product->defaultVariant()->first(), 1);
        $lines = app(CartManager::class)->lines(null)->map(fn ($l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity])->all();

        return app(CheckoutService::class)->placeAsGuest($email, $lines, ['shipping_address' => '1 Test Street, Cape Town, 8001', 'shipping_name' => 'Guest Person'], (string) Str::uuid())->fresh();
    }

    public function test_the_page_is_public_and_a_dead_guest_link_points_to_it(): void
    {
        $this->get(route('help.order-link'))->assertOk()->assertSee('Get your order link again')->assertSee('E-mail address you ordered with');

        $this->get('/guest/orders/1/not-a-real-token')->assertNotFound()->assertSee('Get my order link again', false)->assertSee(route('help.order-link'), false);
        $this->get('/products/999999')->assertNotFound()->assertDontSee('Get my order link again');
    }

    public function test_a_guest_with_an_order_gets_the_working_link_and_the_screen_says_nothing_about_it(): void
    {
        $order = $this->guestOrder();
        Notification::fake();

        $this->followingRedirects()->post(route('help.order-link.send'), ['email' => 'Lost.Link@Example.test'])->assertOk()->assertSee('If that address was used to place an order here as a guest');

        Notification::assertSentOnDemand(CommerceNotification::class, function (CommerceNotification $notification, array $channels, $notifiable) use ($order) {
            return $notification->kind === 'order_link' && $notification->orderId === $order->id && $notifiable->routes['mail'] === 'lost.link@example.test';
        });
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_mail(): void
    {
        $this->guestOrder();
        Notification::fake();

        $this->followingRedirects()->post(route('help.order-link.send'), ['email' => 'nobody@example.test'])->assertOk()->assertSee('If that address was used to place an order here as a guest');

        Notification::assertNothingSent();
    }

    public function test_an_expired_link_is_replaced_and_the_old_one_stays_dead(): void
    {
        $order = $this->guestOrder();
        Notification::fake();
        $access = app(GuestOrderAccess::class);
        $oldUrl = $access->url($order);
        $order->forceFill(['guest_token_expires_at' => now()->subDay()])->save();
        $this->assertNull($access->url($order->fresh()), 'expired');

        $this->post(route('help.order-link.send'), ['email' => 'lost.link@example.test'])->assertRedirect();

        $fresh = $order->fresh();
        $newUrl = $access->url($fresh);
        $this->assertNotNull($newUrl);
        $this->assertNotSame($oldUrl, $newUrl);
        $this->get($newUrl)->assertRedirect(route('guest.orders.show', $order));
        $this->flushSession();
        $this->get($oldUrl)->assertNotFound();
        Notification::assertSentOnDemandTimes(CommerceNotification::class, 1);
    }

    public function test_a_revoked_link_gets_nothing(): void
    {
        $order = $this->guestOrder();
        Notification::fake();
        app(GuestOrderAccess::class)->revoke($order);

        $this->post(route('help.order-link.send'), ['email' => 'lost.link@example.test'])->assertRedirect();
        Notification::assertNothingSent();
    }

    public function test_a_malformed_address_is_a_validation_error_and_the_form_is_rate_limited_per_address(): void
    {
        $this->guestOrder();
        Notification::fake();
        $this->from(route('help.order-link'))->post(route('help.order-link.send'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('help.order-link.send'), ['email' => 'lost.link@example.test'])->assertRedirect();
        }
        $this->post(route('help.order-link.send'), ['email' => 'lost.link@example.test'])->assertStatus(429);
    }

    public function test_a_wrong_method_and_an_oversized_upload_get_designed_pages_not_the_framework_default(): void
    {
        $this->get('/cart/promo')->assertStatus(405)->assertSee('That address does not work that way')->assertDontSee('Something is broken');

        $this->call('POST', '/cart/promo', [], [], [], ['CONTENT_LENGTH' => 999999999999])->assertStatus(413)->assertSee('That upload is too big');
    }
}
