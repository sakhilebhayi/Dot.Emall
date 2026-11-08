<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Services\Cart\CartManager;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/** R-43 security pass: the magic link of a guest order (D-9) after it has been followed. */
class GuestAccessTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function guestOrder(string $email = 'guest@example.test'): Order
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 80, 'stock' => 5]);
        app(CartManager::class)->add(null, $product->defaultVariant()->first(), 1);
        $lines = app(CartManager::class)->lines(null)->map(fn ($l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity])->all();

        return app(CheckoutService::class)->placeAsGuest($email, $lines, ['shipping_address' => '1 Test Street, Cape Town, 8001', 'shipping_name' => 'Guest Person'], (string) Str::uuid());
    }

    public function test_rotating_the_link_also_ends_the_access_a_browser_already_gained_with_the_old_one(): void
    {
        $order = $this->guestOrder();
        $access = app(GuestOrderAccess::class);
        $link = $access->url($order);

        // One browser follows the link (it might be the guest's, or the person the link leaked to).
        $this->get($link)->assertRedirect(route('guest.orders.show', $order));
        $this->get(route('guest.orders.show', $order))->assertOk();

        $access->rotate($order->fresh());

        $this->get(route('guest.orders.show', $order))->assertNotFound();
        $this->post(route('guest.orders.message', $order), ['group' => 1, 'body' => 'still here'])->assertNotFound();
        $this->get(route('guest.orders.receipt', $order))->assertNotFound();
    }

    public function test_the_new_link_after_a_rotation_opens_the_order_again(): void
    {
        $order = $this->guestOrder();
        $access = app(GuestOrderAccess::class);
        $this->get($access->url($order))->assertRedirect();

        $access->rotate($order->fresh());
        $this->get($access->url($order->fresh()))->assertRedirect(route('guest.orders.show', $order));
        $this->get(route('guest.orders.show', $order))->assertOk();
    }

    public function test_revoking_the_link_ends_every_browsers_access_at_once(): void
    {
        $order = $this->guestOrder();
        $access = app(GuestOrderAccess::class);
        $this->get($access->url($order))->assertRedirect();

        $access->revoke($order->fresh());

        $this->get(route('guest.orders.show', $order))->assertNotFound();
    }
}
