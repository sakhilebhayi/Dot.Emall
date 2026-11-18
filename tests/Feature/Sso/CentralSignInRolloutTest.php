<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Services\Cart\CartManager;
use App\Services\CheckoutService;
use App\Sso\EmallProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/** R-54: Dot.Emall behind DOTSSO_ENABLED. Off, nothing changes; on, every sign-in entry goes to InfoDot, the public shop stays public. */
class CentralSignInRolloutTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config(['dotsso.provisioner' => EmallProvisioner::class, 'dotsso.enabled' => false]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    /** Guest checkout is the shop's own flow: it may redirect (an empty cart), but never to a sign-in. */
    private function assertNotLoginRedirect($response): void
    {
        $to = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('/login', $to);
        $this->assertStringNotContainsString('/sso/login', $to);
        $this->assertStringNotContainsString('/sign-in', $to);
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create();

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/sign-in?return=/cart')->assertRedirect(route('login'));
        $this->get('/cart')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
        $this->get('/sso/callback')->assertNotFound();
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_public_shop_stays_public_and_its_sign_in_links_return_to_the_page(): void
    {
        $this->on();

        // No redirect to a login: the cart, the product list and the help pages are guest pages.
        $html = $this->get('/cart')->assertOk()->getContent();
        $this->assertStringContainsString(route('sign-in', ['return' => '/cart']), $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
        $this->get('/products')->assertOk();
        $this->assertNotLoginRedirect($this->get('/checkout'));
    }

    public function test_the_header_offers_create_account_only_while_the_central_sign_in_is_on(): void
    {
        $this->get('/products')->assertOk()->assertDontSee('Create account');

        $this->on();
        $this->get('/products')->assertOk()->assertSee('Create account')->assertSee(route('dotsso.login', ['screen' => 'register']), false);
    }

    public function test_sign_in_from_a_public_page_comes_back_to_that_page(): void
    {
        $this->on();

        $this->get('/sign-in?return=/cart')->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-uuid-1', 'email' => 'shopper@example.test'])->assertRedirect('/cart');
        $this->assertAuthenticated();
    }

    public function test_the_return_value_of_sign_in_is_never_an_open_redirect_or_a_sign_in_door(): void
    {
        $this->on();

        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', '/login', '/sso/login', '/guest/orders/1/abc', '/webhooks/paystack'] as $bad) {
            $this->flushSession();
            $this->get('/sign-in?return='.urlencode($bad))->assertRedirect(route('dotsso.login'));
            $this->assertNull(session('url.intended'), $bad);
        }

        $this->flushSession();
        $this->get('/sign-in?return=/cart')->assertRedirect(route('dotsso.login'));
        $this->assertSame('/cart', session('url.intended'));
    }

    public function test_guest_checkout_and_magic_link_order_pages_are_never_intercepted(): void
    {
        $this->on();

        $this->get('/guest/orders/999/not-a-token')->assertStatus(404);
        $this->get('/help/order-link')->assertOk();
        $this->assertNotLoginRedirect($this->get('/checkout'));
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/cart')->assertOk()->assertSee(route('login'), false);
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/orders')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/orders');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_seller_trust_evidence_is_stamped_on_every_central_sign_in(): void
    {
        $this->on();

        // A new account.
        $this->signInVia(['sub' => 'infodot-s-1', 'email' => 'seller@example.test']);
        $seller = User::where('email', 'seller@example.test')->firstOrFail();
        $this->assertNotNull($seller->infodot_signed_in_at, 'first sign-in');

        // A later sign-in refreshes it.
        auth()->logout();
        $seller->forceFill(['infodot_signed_in_at' => now()->subDays(40)])->save();
        $this->signInVia(['sub' => 'infodot-s-1', 'email' => 'seller@example.test']);
        $this->assertTrue($seller->fresh()->infodot_signed_in_at->isToday());

        // A verified local account linked at first sign-in.
        $local = User::factory()->create(['email' => 'local@example.test', 'email_verified_at' => now()]);
        $this->assertNull($local->infodot_signed_in_at);
        auth()->logout();
        $this->signInVia(['sub' => 'infodot-s-2', 'email' => 'local@example.test']);
        $this->assertNotNull($local->fresh()->infodot_signed_in_at);
    }

    public function test_a_guest_order_attaches_to_the_account_at_the_first_central_sign_in_with_the_same_verified_email(): void
    {
        $this->on();
        $product = $this->makeListing($this->makeSeller(), ['price' => 80, 'stock' => 5]);
        app(CartManager::class)->add(null, $product->defaultVariant()->first(), 1);
        $lines = app(CartManager::class)->lines(null)->map(fn ($l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity])->all();
        $order = app(CheckoutService::class)->placeAsGuest('Thandi@Example.test', $lines, ['shipping_address' => '1 Test Street, Cape Town, 8001', 'shipping_name' => 'Thandi'], (string) Str::uuid());
        $this->assertNull($order->user_id);

        $this->signInVia(['sub' => 'infodot-g-1', 'email' => 'thandi@example.test']);

        $this->assertSame(User::where('email', 'thandi@example.test')->value('id'), $order->fresh()->user_id);
    }

    public function test_a_local_password_sign_in_never_stamps_the_trust_evidence(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-pass-123')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123']);

        $this->assertNull($user->fresh()->infodot_signed_in_at);
    }

    public function test_an_unverified_local_user_claims_with_a_code_keeps_data_and_gets_the_trust_stamp(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->infodot_signed_in_at, 'nothing is stamped before the code is entered');

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->infodot_signed_in_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect(route('dotsso.login'));
        $this->post('/webhooks/paystack', ['event' => 'charge.success'], ['x-paystack-signature' => 'bad'])->assertStatus(401);
    }

    public function test_an_operator_keeps_the_operator_flag_when_linked_at_first_sign_in(): void
    {
        $this->on();
        $op = User::factory()->withPersonalTeam()->create(['email' => 'op@example.test', 'email_verified_at' => now(), 'is_platform_operator' => true]);

        $this->signInVia(['sub' => 'infodot-op-1', 'email' => 'op@example.test']);

        $op->refresh();
        $this->assertSame('infodot-op-1', $op->infodot_user_uuid);
        $this->assertTrue((bool) $op->is_platform_operator);
    }

    public function test_a_new_infodot_person_is_never_made_an_operator(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-new-2', 'email' => 'fresh@example.test']);

        $this->assertFalse((bool) User::where('email', 'fresh@example.test')->firstOrFail()->is_platform_operator);
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => 'infodot-u-3']);

        $this->signInVia(['sub' => 'infodot-u-3', 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_the_error_page_has_one_h1_a_reference_and_a_way_back(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?error=access_denied&state=x')->assertStatus(400)->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Back to Dot.Emall', $html);
        $this->assertStringContainsString('Reference:', $html);
    }

    public function test_the_team_invitation_mail_points_new_people_at_the_central_registration(): void
    {
        $this->on();
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Seller Co', 'personal_team' => false]);
        $invitation = $team->teamInvitations()->create(['email' => 'x@example.test', 'role' => 'editor']);

        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), (new TeamInvitation($invitation))->render());
    }

    public function test_a_back_channel_logout_ends_a_signed_in_session_on_a_sanctum_guarded_route(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-bc-1', 'email' => 'bc@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-bc-1', 'revoked_at_ms' => (int) floor(microtime(true) * 1000) + 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect();
        $this->assertFalse(session()->has('dotsso.session'), 'the SSO session was invalidated');
    }
}
