<?php

namespace Tests\Feature\Commerce;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use App\Services\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cart(): CartManager
    {
        return app(CartManager::class);
    }

    private function guestOrder(string $email = 'guest@example.test', array $input = []): Order
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 80, 'stock' => 5]);
        $this->cart()->add(null, $product->defaultVariant()->first(), 2);
        $lines = $this->cart()->lines(null)->map(fn ($l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity])->all();

        return app(CheckoutService::class)->placeAsGuest($email, $lines, array_merge(['shipping_address' => '1 Test Street, Cape Town, 8001', 'shipping_name' => 'Guest Person'], $input), (string) Str::uuid());
    }

    // ---- the session cart --------------------------------------------------------------------------------------------

    public function test_a_guest_keeps_a_cart_in_the_session_with_the_same_stock_rules(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3, 'price' => 10]);
        $variant = $product->defaultVariant()->first();

        $this->cart()->add(null, $variant, 2);
        $this->cart()->add(null, $variant, 1);
        $this->assertSame(3, $this->cart()->count(null));

        try {
            $this->cart()->add(null, $variant, 1);
            $this->fail('over the stock');
        } catch (CartProblem $e) {
            $this->assertStringContainsString('Only 3', $e->getMessage());
        }

        $this->cart()->setQuantity(null, $variant->id, 1);
        $this->assertSame(1, $this->cart()->lines(null)->first()->quantity);

        $this->cart()->remove(null, $variant->id);
        $this->assertSame(0, $this->cart()->count(null));
    }

    public function test_a_guest_cart_cannot_hold_an_unlisted_product(): void
    {
        $pending = $this->makeListing($this->makeSeller(), ['status' => 'pending_approval']);

        $this->expectException(CartProblem::class);
        $this->cart()->add(null, $pending->defaultVariant()->first(), 1);
    }

    public function test_signing_in_merges_the_guest_cart_into_the_accounts_cart(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['stock' => 10]);
        $b = $this->makeListing($seller, ['stock' => 1]);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $a->id, 'quantity' => 2]);

        $this->cart()->add(null, $a->defaultVariant()->first(), 3);
        $this->cart()->add(null, $b->defaultVariant()->first(), 1);
        $this->assertSame(4, $this->cart()->count(null));

        Auth::login($buyer); // fires the Login event, as a real sign-in does

        $lines = CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->orderBy('product_id')->get();
        $this->assertSame([5, 1], $lines->pluck('quantity')->all(), 'quantities add up');
        $this->assertSame(0, $this->cart()->count(null), 'the session cart is emptied');
    }

    public function test_the_merge_caps_to_stock_and_drops_the_buyers_own_listing(): void
    {
        $other = $this->makeSeller();
        $stocked = $this->makeListing($other, ['stock' => 4]);
        $buyer = $this->makeSeller(); // a seller shopping
        $own = $this->makeListing($buyer, ['stock' => 4]);
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $stocked->id, 'quantity' => 3]);

        $this->cart()->add(null, $stocked->defaultVariant()->first(), 3);
        $this->cart()->add(null, $own->defaultVariant()->first(), 1);

        Auth::login($buyer);

        $this->assertSame(4, (int) CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->where('product_id', $stocked->id)->value('quantity'));
        $this->assertSame(0, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->where('product_id', $own->id)->count());
    }

    // ---- guest checkout ----------------------------------------------------------------------------------------------

    public function test_a_guest_can_check_out_with_just_an_email_and_gets_an_order_with_a_link(): void
    {
        $order = $this->guestOrder('Guest@Example.test');

        $this->assertNull($order->user_id);
        $this->assertSame('guest@example.test', $order->guest_email, 'stored lower-case');
        $this->assertTrue($order->isGuest());
        $this->assertSame('160.00', $order->total);
        $this->assertSame('guest@example.test', $order->buyerEmail());
        $this->assertNotNull($order->guest_token_hash);
        $this->assertNotNull($order->guest_token_expires_at);
        $this->assertSame(64, strlen($order->guest_token_hash));

        $url = app(GuestOrderAccess::class)->url($order);
        $this->assertStringContainsString("/guest/orders/{$order->id}/", $url);
        $this->assertStringNotContainsString($order->guest_token_hash, $url, 'only a hash is kept at rest');
    }

    public function test_a_guest_checkout_needs_a_valid_email_and_a_cart(): void
    {
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller)->defaultVariant()->first();
        $service = app(CheckoutService::class);

        foreach (['', 'nobody', 'a@b', str_repeat('x', 200).'@example.test'] as $bad) {
            try {
                $service->placeAsGuest($bad, [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => 'x']);
                $this->fail("{$bad} should be refused");
            } catch (CheckoutException $e) {
                $this->assertStringContainsString('e-mail', $e->getMessage());
            }
        }

        $this->expectExceptionMessage('Your cart is empty.');
        $service->placeAsGuest('ok@example.test', [], ['shipping_address' => 'x']);
    }

    public function test_a_guest_submit_twice_places_one_order(): void
    {
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller, ['stock' => 5])->defaultVariant()->first();
        $lines = [['variant_id' => $variant->id, 'quantity' => 1]];
        $token = (string) Str::uuid();
        $service = app(CheckoutService::class);

        $first = $service->placeAsGuest('g@example.test', $lines, ['shipping_address' => 'x'], $token);
        $again = $service->placeAsGuest('g@example.test', $lines, ['shipping_address' => 'x'], $token);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(4, $variant->product->fresh()->stock);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_guest_still_pays_through_paystack_with_their_email(): void
    {
        $this->enablePaystack();
        $order = $this->guestOrder();
        $this->assertTrue($order->requires_payment);

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => function ($request) {
            $this->assertSame('guest@example.test', $request->data()['email']);

            return \Illuminate\Support\Facades\Http::response(['status' => true, 'message' => 'ok', 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => $request->data()['reference']]], 200);
        }]);

        $payment = app(\App\Services\PaymentService::class)->startPayment($order);

        $this->assertNotNull($payment->authorization_url);
    }

    public function test_at_most_three_unpaid_orders_per_guest_email(): void
    {
        $this->enablePaystack();
        foreach (range(1, 3) as $n) {
            $this->guestOrder('same@example.test');
            $this->cart()->clear(null);
        }

        try {
            $this->guestOrder('same@example.test');
            $this->fail('the fourth unpaid order must be refused');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('3 orders waiting for payment', $e->getMessage());
        }

        $this->guestOrder('someone-else@example.test'); // another address is not affected
        $this->assertSame(4, Order::query()->count());
    }

    // ---- the magic link: tokens, IDOR, rotation ----------------------------------------------------------------------

    public function test_the_right_link_opens_that_one_order_and_nothing_else(): void
    {
        $mine = $this->guestOrder('me@example.test');
        $this->cart()->clear(null);
        $theirs = $this->guestOrder('them@example.test');
        $access = app(GuestOrderAccess::class);

        $this->getJson($access->url($mine))->assertOk()->assertJsonPath('order', $mine->reference())->assertJsonPath('lines.0.quantity', 2);

        // My token on their order id, their token on mine, no token, and a guess: all the same answer.
        $myToken = $access->tokenFor($mine);
        $theirToken = $access->tokenFor($theirs);
        foreach ([
            "/guest/orders/{$theirs->id}/{$myToken}",
            "/guest/orders/{$mine->id}/{$theirToken}",
            "/guest/orders/{$mine->id}/".str_repeat('a', 43),
            "/guest/orders/999999/{$myToken}",
        ] as $url) {
            $this->getJson($url)->assertNotFound();
        }

        $this->assertTrue($access->hasSessionGrant($mine->fresh()));
        $this->assertFalse($access->hasSessionGrant($theirs->fresh()), 'following my link granted nothing on their order');
    }

    public function test_a_tokens_shape_is_enforced_before_anything_is_looked_up(): void
    {
        $order = $this->guestOrder();

        $this->getJson("/guest/orders/{$order->id}/short")->assertNotFound();
        $this->getJson("/guest/orders/{$order->id}/".str_repeat('a', 300))->assertNotFound();
        $this->getJson("/guest/orders/{$order->id}/".str_repeat('a b', 10))->assertNotFound();
    }

    public function test_an_order_that_belongs_to_an_account_cannot_be_opened_with_a_link_even_a_valid_looking_one(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);
        $fake = rtrim(strtr(base64_encode(hash_hmac('sha256', 'guest-order|'.$order->id.'||1', config('app.key'), true)), '+/', '-_'), '=');

        $this->assertNull(app(GuestOrderAccess::class)->tokenFor($order));
        $this->getJson("/guest/orders/{$order->id}/{$fake}")->assertNotFound();
    }

    public function test_rotating_the_token_kills_the_old_links_and_revoking_kills_them_all(): void
    {
        $order = $this->guestOrder();
        $access = app(GuestOrderAccess::class);
        $old = $access->tokenFor($order);

        $new = $access->rotate($order->fresh());

        $this->assertNotSame($old, $new);
        $this->getJson("/guest/orders/{$order->id}/{$old}")->assertNotFound();
        $this->getJson("/guest/orders/{$order->id}/{$new}")->assertOk();

        $access->revoke($order->fresh());
        $this->getJson("/guest/orders/{$order->id}/{$new}")->assertNotFound();
        $this->assertNull($access->url($order->fresh()));
    }

    public function test_a_link_expires(): void
    {
        $order = $this->guestOrder();
        $token = app(GuestOrderAccess::class)->tokenFor($order);

        Carbon::setTestNow(now()->addDays(181));

        $this->getJson("/guest/orders/{$order->id}/{$token}")->assertNotFound();
    }

    public function test_the_token_is_recomputable_so_later_emails_carry_the_same_working_link(): void
    {
        $order = $this->guestOrder();
        $access = app(GuestOrderAccess::class);

        $this->assertSame($access->tokenFor($order), $access->tokenFor($order->fresh()));
        $this->assertTrue($access->verify($order->fresh(), $access->tokenFor($order)));
        $this->assertFalse($access->verify($order->fresh(), ''));
    }

    public function test_following_links_is_rate_limited(): void
    {
        $order = $this->guestOrder();
        $token = str_repeat('a', 43);

        foreach (range(1, 10) as $i) {
            $this->getJson("/guest/orders/{$order->id}/{$token}")->assertNotFound();
        }

        $this->getJson("/guest/orders/{$order->id}/{$token}")->assertStatus(429);
    }

    public function test_the_hash_is_never_serialised_and_the_token_columns_are_not_mass_assignable(): void
    {
        $order = $this->guestOrder();

        $this->assertArrayNotHasKey('guest_token_hash', $order->fresh()->toArray());

        $forged = Order::create(['user_id' => null, 'guest_email' => 'x@example.test', 'guest_token_hash' => str_repeat('a', 64), 'status' => 'pending', 'subtotal' => 0, 'total' => 0]);
        $this->assertNull($forged->fresh()->guest_token_hash);
    }

    public function test_other_signed_in_users_and_sellers_cannot_open_a_guest_order(): void
    {
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller)->defaultVariant()->first();
        $order = app(CheckoutService::class)->placeAsGuest('g@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => 'x']);
        $stranger = $this->makeBuyer();

        $this->actingAs($stranger)->get(route('orders.show', $order))->assertForbidden();
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $order));
        $this->assertTrue(Gate::forUser($seller)->allows('view', $order), 'the seller of a line may open it, as for any order');
    }

    public function test_the_guest_view_is_the_buyers_view_of_that_one_order(): void
    {
        $order = $this->guestOrder();

        $view = app(OrderAccess::class)->presentGuest($order);

        $this->assertSame(OrderAccess::GUEST, $view['role']);
        $this->assertTrue($view['fullOrder']);
        $this->assertSame('1 Test Street, Cape Town, 8001', $view['shipping']['address']);
        $this->assertCount(1, $view['lines']);
    }

    // ---- attaching to an account -------------------------------------------------------------------------------------

    public function test_guest_orders_attach_when_the_same_verified_email_signs_in(): void
    {
        $order = $this->guestOrder('Thandi@Example.test');
        $this->cart()->clear(null);
        $other = $this->guestOrder('someone@else.test');
        $user = User::factory()->withPersonalTeam()->create(['email' => 'thandi@example.test', 'email_verified_at' => now()]);
        $token = app(GuestOrderAccess::class)->tokenFor($order);

        Auth::login($user);

        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->assertNotNull($order->fresh()->guest_attached_at);
        $this->assertNull($other->fresh()->user_id, 'an unrelated address is untouched');
        $this->getJson("/guest/orders/{$order->id}/{$token}")->assertNotFound();
        $this->actingAs($user)->get(route('orders.show', $order))->assertOk();
    }

    public function test_an_unverified_account_never_inherits_guest_orders(): void
    {
        $order = $this->guestOrder('thandi@example.test');
        $user = User::factory()->create(['email' => 'thandi@example.test', 'email_verified_at' => null]);

        Auth::login($user);

        $this->assertNull($order->fresh()->user_id);
    }

    public function test_notices_to_a_guest_go_by_email_with_their_order_link(): void
    {
        Notification::fake();
        $order = $this->guestOrder('g@example.test');

        Notification::assertSentOnDemand(\App\Notifications\CommerceNotification::class, function ($notification, $channels, $notifiable) use ($order) {
            return $channels === ['mail'] && $notifiable->routes['mail'] === 'g@example.test' && $notification->kind === 'order_placed' && $notification->orderId === $order->id;
        });
    }

    public function test_the_guest_email_contains_a_working_link(): void
    {
        $order = $this->guestOrder('g@example.test');
        $notice = new \App\Notifications\CommerceNotification('order_placed', $order->id);
        $mail = $notice->toMail(Notification::route('mail', 'g@example.test'));

        $this->assertSame(app(GuestOrderAccess::class)->url($order), $mail->actionUrl);
        $this->getJson($mail->actionUrl)->assertOk();
    }
}
