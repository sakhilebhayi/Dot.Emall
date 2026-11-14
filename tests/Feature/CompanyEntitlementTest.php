<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * R-50: Dot.Emall honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on. Orders that already exist, the person's own
 * data rights and the operators' desk are exempt, and the public shop never asks. Response bodies follow InfoDot's
 * own tests (CompanyPlatformsEndpointTest, the redeem controller).
 */
class CompanyEntitlementTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'emall', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'emall',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'emall', 'name' => 'Dot.Emall', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team, 2: Product} an owner with a store and a listing, and their InfoDot company */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $store = Store::query()->where('owner_id', $owner->id)->first() ?? Store::factory()->create(['owner_id' => $owner->id, 'name' => 'Karoo Biltong Co']);
        $product = Product::factory()->create(['store_id' => $store->id, 'name' => 'Karoo Biltong Sticks '.Str::random(4), 'is_active' => true, 'stock' => 20]);

        return [$owner->fresh(), $team, $product];
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'emall',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        // Globex exists here already under an older name, with Ann as a viewer working in it.
        [$globexOwner, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first(), 'a company that does not hold the platform is not created');
        $this->assertSame('Globex (old name)', $globex->fresh()->name, 'nor renamed');
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role, 'nor is the role changed');
        $this->assertTrue($ann->belongsToTeam($globex), 'membership is left as it was');
        $this->assertSame($acme->id, $ann->current_team_id, 'and it cannot be the current company');
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam('44444444-4444-4444-8444-444444444444', "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Emall needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/emall"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action='));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created for nothing');
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    /** Pages inside the checked area, none of them an order page: they show the person's own store and account. */
    private function gatedUrls(): array
    {
        return [route('dashboard'), route('account.home'), route('account.addresses'), route('seller.dashboard'), route('seller.products.index'), route('wishlist.index')];
    }

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('seller.products.index'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'emall'
            && $request->hasHeader('X-Dot-Platform', 'emall')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Emall is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Emall is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Emall needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Emall has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        [$owner, $team, $product] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        foreach ([...$this->gatedUrls(), route('teams.show', $team)] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/emall"', false)
                ->assertDontSee('Karoo Biltong');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action='), $url);
        }

        // Nothing is changed either.
        $this->actingAs($owner)->patch(route('seller.products.toggle-active', $product))->assertStatus(403);
        $this->actingAs($owner)->post(route('account.addresses.store'), ['label' => 'Home'])->assertStatus(403);
        $this->assertTrue((bool) $product->fresh()->is_active, 'the data is untouched');
        $this->assertSame(0, DB::table('addresses')->count());
    }

    public function test_orders_that_already_exist_stay_open_on_both_sides_while_the_company_is_refused(): void
    {
        // The refused person is a seller (a buyer ordered from their store) and also a buyer (they ordered from another store).
        [$owner, , $listing] = $this->company();
        $other = $this->makeSeller();
        $theirs = $this->makeListing($other, ['price' => 60, 'stock' => 20]);
        $bought = $this->placeOrder($owner, [[$theirs, 1]]);
        $sold = $this->placeOrder($this->makeBuyer(), [[$listing, 1]]);
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($owner)->get(route('orders.index'))->assertOk();
        $this->actingAs($owner)->get(route('orders.show', $bought))->assertOk();
        $this->actingAs($owner)->get(route('orders.receipt', $bought))->assertOk();
        $this->actingAs($owner)->get(route('seller.orders.index'))->assertOk();
        $this->actingAs($owner)->get(route('seller.orders.show', $sold->groups()->firstOrFail()))->assertOk();
        $this->actingAs($owner)->get(route('account.privacy'))->assertOk();

        // Paying an open order is not blocked either (what it answers is the payment's own business, never the refusal).
        $this->assertNotSame(403, $this->actingAs($owner)->post(route('orders.pay', $bought))->getStatusCode());
        $this->assertNotSame(403, $this->actingAs($owner)->post(route('orders.payment.check', $bought))->getStatusCode());

        // ...while new things are not: the desk, new listings, the account pages.
        $this->actingAs($owner)->get(route('seller.dashboard'))->assertStatus(403);
        $this->actingAs($owner)->get(route('seller.products.create'))->assertStatus(403);
    }

    public function test_the_public_shop_and_the_webhook_never_ask(): void
    {
        [$owner, , $product] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($owner)->get(route('products.index'))->assertOk();
        $this->actingAs($owner)->get(route('products.show', $product))->assertOk()->assertSee('Karoo Biltong Sticks');
        $this->actingAs($owner)->get(route('cart.index'))->assertOk();
        $this->post(route('webhooks.paystack'), [])->assertStatus(401);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/ecosystem/companies/'));
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'emall', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'emall', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'emall', 'platforms' => [['key' => 'emall', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'emall', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->actingAs($owner)->get(route('seller.products.index'))->assertOk();
        $this->actingAs($owner)->get(route('account.home'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('account.home'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get(route('account.home'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get(route('account.home'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get(route('account.home'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('account.home'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('account.home'))->assertOk();
        $this->actingAs($owner)->get(route('account.addresses'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('account.home'))->assertOk();
        $this->actingAs($owner)->get(route('seller.products.index'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('EMALL_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_a_personal_workspace_or_a_company_infodot_does_not_know_is_not_asked_about(): void
    {
        $this->infodotSays([]);

        $solo = User::factory()->withPersonalTeam()->create();
        $this->actingAs($solo)->get(route('account.home'))->assertOk();

        $local = Team::forceCreate(['user_id' => $solo->id, 'name' => 'Local Co', 'personal_team' => false]);
        $solo->forceFill(['current_team_id' => $local->id])->save();
        $this->actingAs($solo->fresh())->get(route('account.home'))->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ personal workspaces (individuals hold the free core only)

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    /** Ann signs in with one entitled company and a personal workspace marked as given, then works in her local personal team. */
    private function signInThenWorkPersonally(?bool $personalEntitled): array
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('dashboard'));

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $this->withSession(session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : []);

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $personal = $ann->personalTeam();
        $store = Store::factory()->create(['owner_id' => $ann->id, 'name' => 'Ann Private Stall']);
        $ann->forceFill(['current_team_id' => $personal->id])->save();

        $this->actingAs($ann->fresh());

        return [$ann->fresh(), $personal, $store, Team::where('infodot_team_uuid', self::ACME)->firstOrFail()];
    }

    public function test_a_buyer_with_no_company_cannot_open_the_account_or_sell_when_infodot_says_individuals_hold_the_core_only(): void
    {
        [$ann, $personal, $store, $acme] = $this->signInThenWorkPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        foreach ($this->gatedUrls() as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.Emall needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/emall"', false)
                ->assertSee('name="team_id" value="'.$acme->id.'"', false)
                ->assertDontSee('Ann Private Stall');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action='), $url);
        }

        Http::assertNothingSent();

        // What stays: browsing and the cart, orders, one's own data, the profile. Nothing is deleted or altered.
        $this->get(route('products.index'))->assertOk();
        $this->get(route('cart.index'))->assertOk();
        $this->get(route('orders.index'))->assertOk();
        $this->get(route('account.privacy'))->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->assertTrue((bool) $store->fresh()->is_active);

        // The entitled company can still be switched to.
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get(route('account.home'))->assertOk();
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        [$ann] = $this->signInThenWorkPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local Co', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();
        $this->infodotSays([]);

        $this->get(route('account.home'))->assertStatus(403)->assertSee('Dot.Emall needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('account.home'))->assertOk();
        $this->get(route('seller.products.index'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('account.home'))->assertOk();
        $this->get(route('seller.products.index'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_person_who_signed_in_without_infodot_is_not_refused_as_a_personal_workspace(): void
    {
        $solo = User::factory()->withPersonalTeam()->create();
        $this->infodotSays([]);

        $this->actingAs($solo)->get(route('account.home'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('account.home'))->assertOk();
    }

    /** The notification bell's own snapshot, as the browser would send it. */
    private function bellSnapshot(): string
    {
        $page = $this->get(route('account.home'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $all);
        foreach ($all[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);
            if (str_contains($snapshot, 'notification-bell')) {
                return $snapshot;
            }
        }
        $this->fail('the notification bell is on the page');
    }

    private function livewireMarkAllRead(string $snapshot)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'markAllAsRead', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner] = $this->company();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $snapshot = $this->bellSnapshot();

        // While the company holds the platform the action works.
        $worked = $this->livewireMarkAllRead($snapshot);
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        DB::table('notifications')->where('id', $id)->update(['read_at' => null]);

        // The cached answer expires, InfoDot now says no: the same action is refused and returns no component.
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->livewireMarkAllRead($snapshot);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"components"', $response->getContent());
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'), 'the data is unchanged');
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner, , $product] = $this->company();
        $this->infodotSays([]);

        // Signed out: the public pages, health, sign-in, the handoff receiver's legacy GET.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get(route('products.show', $product))->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');

        // Signed in, in a company that would be refused if anyone asked: the same routes, the person's own account,
        // existing orders, and signing out.
        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get(route('orders.index'))->assertOk();
        $this->get(route('seller.orders.index'))->assertOk();
        $this->get(route('account.privacy'))->assertOk();
        $this->get('/up')->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        // The refusal names the other company and offers the switch.
        $this->actingAs($owner->fresh())->get(route('account.home'))->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get(route('account.home'))->assertOk();
        $this->get(route('teams.show', $globex))->assertOk();

        // The suspended company stays shut even by its address.
        $this->get(route('teams.show', $acme))->assertStatus(403)->assertSee('Dot.Emall is suspended for Acme Ltd');
    }

    public function test_another_companys_page_is_never_opened_or_asked_about_by_naming_it_in_the_address(): void
    {
        [$owner] = $this->company();
        [, $other] = $this->company(self::GLOBEX, 'Globex Corporation');
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('teams.show', $other))->assertForbidden()->assertDontSee('Globex Corporation');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }
}
