<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * R-51: the header carries the shared Dot UI (dot-ui). The shop's search is now the shared pill (its form, route, parameter, suggestions and results did
 * not change), and the signed-in account and the seller and operator desks carry the platform switcher. The switcher only ever shows what InfoDot says the
 * CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token). The public shop never draws it, so a catalogue
 * page never asks InfoDot, for a guest or for a signed-in person.
 */
class DotUiHeaderTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use BuildsOperatorWorld;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalogue();
        Cache::flush();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'emall', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['emall', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['emall' => 'Dot.Emall', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'emall', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** A person whose current company is the InfoDot company $uuid, with a store (so the seller desk opens too). */
    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        Store::factory()->create(['owner_id' => $user->id]);
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    /** The markup of the search pill only. */
    private function pillHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsr dsr--nohint".*?<\/form>.*?<\/div>\s*<\/div>/s', $html, $m), 'the header carries the search pill');

        return $m[0];
    }

    // ── The search pill: the shop's own search, presentation only ───────────

    public function test_the_public_shop_search_is_the_shared_pill_with_the_same_form_route_and_parameter(): void
    {
        $this->fakeInfoDot([]);

        $html = $this->get(route('products.index', ['q' => 'skillet']))->assertOk()->getContent();
        $pill = $this->pillHtml($html);

        $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"/', $pill, $form), 'a GET search form');
        $this->assertSame(route('search'), html_entity_decode($form[1]), 'it still goes to the shop search');
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="q"[^>]*\bvalue="skillet"/', $pill, 'same parameter, current value kept');
        $this->assertStringContainsString('placeholder="Search products, brands and stores"', $pill);
        $this->assertStringContainsString('id="site-search-input"', $pill);
        $this->assertStringContainsString('class="dsr-input"', $pill);
        $this->assertStringContainsString('role="combobox"', $pill, 'still a combobox over the suggestion list');
        $this->assertStringContainsString('data-suggest-endpoint="'.route('search.suggest').'"', $pill, 'the suggestions still come from the shop endpoint');
        $this->assertStringContainsString('data-suggest-list', $pill);
        $this->assertStringNotContainsString('ds-search__form', $html, 'the old search field is gone from the header');
        $this->assertSame(1, substr_count($html, 'role="search"'), 'one search landmark in the header');
        $this->assertStringNotContainsString('dsr-kbd', $html, 'no invented Ctrl K shortcut');
        Http::assertNothingSent();
    }

    public function test_the_account_pages_carry_the_same_pill(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk()->getContent();

        $this->assertStringContainsString('class="dsr-input"', $html);
        $this->assertStringContainsString('action="'.route('search').'"', $html);
    }

    public function test_search_results_and_suggestions_still_show_only_listings_open_to_shoppers(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $open = $this->store('Vaal Traders');
        $closed = $this->store('Closed Shop', ['is_active' => false]);
        $this->listing($open, 'Distinctive Cast Skillet 26cm', 529.0, ['stock' => 5]);
        $this->listing($open, 'Distinctive Pending Skillet', 300.0, ['status' => 'pending_approval']);
        $this->listing($closed, 'Distinctive Closed Skillet', 300.0, ['stock' => 5]);
        $this->reindex();
        $buyer = $this->memberOf(self::ACME, 'Acme Ltd');

        $page = $this->actingAs($buyer)->get(route('search', ['q' => 'Distinctive Skillet']))->assertOk();
        $page->assertSee('Distinctive Cast Skillet 26cm')->assertDontSee('Distinctive Pending Skillet')->assertDontSee('Distinctive Closed Skillet');

        $json = $this->actingAs($buyer)->getJson(route('search.suggest', ['q' => 'Distinctive']))->assertOk()->getContent();
        $this->assertStringContainsString('Distinctive Cast Skillet 26cm', $json);
        $this->assertStringNotContainsString('Pending Skillet', $json);
        $this->assertStringNotContainsString('Closed Skillet', $json);

        // The same for a guest: the query, the results and who may see them did not change.
        $this->app['auth']->forgetGuards();
        $this->get(route('search', ['q' => 'Distinctive Skillet']))->assertOk()->assertSee('Distinctive Cast Skillet 26cm')->assertDontSee('Distinctive Pending Skillet');
    }

    // ── Where the switcher is drawn: the signed-in app, never the public shop ──

    public function test_a_guest_sees_the_pill_but_no_switcher_and_nothing_asks_infodot(): void
    {
        $this->fakeInfoDot([]);

        foreach ([route('products.index'), route('login')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('data-dsw', false)->assertDontSee('dot-switcher', false);
        }
        $this->get(route('products.index'))->assertSee('dsr-input', false)->assertSee('dot-search.css', false);
        Http::assertNothingSent();
    }

    public function test_the_public_shop_never_draws_the_switcher_or_asks_infodot_even_for_a_signed_in_person(): void
    {
        $this->fakeInfoDot([]);
        $member = $this->memberOf(self::ACME, 'Acme Ltd');
        $store = $this->store('Vaal Traders');
        $product = $this->listing($store, 'Distinctive Shop Kettle', 449.0, ['stock' => 5]);

        foreach ([route('products.index'), route('products.show', $product), route('stores.index')] as $url) {
            $html = $this->actingAs($member)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-dsw', $html);
            $this->assertStringNotContainsString('dot-switcher', $html, 'its files are not even loaded');
            $this->assertStringContainsString('class="dsr-input"', $html);
        }
        Http::assertNothingSent();
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('account.home'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="emall"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Emall', $here[0]);

        // Held platforms are links through InfoDot, with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_seller_desk_and_the_operator_area_carry_the_switcher_too(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $desk = $this->actingAs($owner)->get(route('seller.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="ok"', $this->switcherHtml($desk));
        $this->assertStringContainsString('dot-switcher.css', $desk);
        $this->assertStringContainsString('data-theme-toggle', $desk, 'the theme switch is still in the desk, in the account menu on a phone');

        $operator = $this->makeOperator();
        $area = $this->actingAs($operator)->get(route('operator.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="personal"', $this->switcherHtml($area), 'an operator in a personal workspace gets the honest sentence');
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'emall');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['emall', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['emall', 'infodot', 'hr'])),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');

        $a = $this->switcherHtml($this->actingAs($acme)->get(route('account.home'))->getContent());
        $g = $this->switcherHtml($this->actingAs($globex)->get(route('account.home'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $a);
        $this->assertStringNotContainsString('/go/hr', $a);
        $this->assertStringNotContainsString(self::GLOBEX, $a);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $g);
        $this->assertStringNotContainsString('/go/press', $g);
        $this->assertStringNotContainsString(self::ACME, $g);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = $this->makeBuyer();

        $html = $this->actingAs($user)->get(route('account.home'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    public function test_the_landing_no_longer_has_the_two_link_back_nav_because_the_switcher_replaces_it(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('ld-back', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('href="https://infodot.test/home"', $html, 'InfoDot stays one click away, from the switcher');
        $this->assertStringContainsString('See all platforms', $html);
    }

    public function test_with_no_infodot_url_set_the_switcher_links_start_with_the_real_infodot_host(): void
    {
        if (getenv('DOT_INFODOT_URL') !== false || isset($_ENV['DOT_INFODOT_URL'])) {
            $this->markTestSkipped('DOT_INFODOT_URL is set in this environment.');
        }

        // The file's own default, as it resolves when the host sets nothing.
        $default = (require config_path('ecosystem.php'))['platforms']['infodot']['url'];
        $this->assertSame('https://infodot.co.za', $default);
        config(['ecosystem.platforms.infodot.url' => $default, 'ecosystem.infodot.redeem_url' => 'https://infodot.co.za/api/ecosystem/redeem']);
        $this->fakeInfoDot(['https://infodot.co.za/api/ecosystem/companies/'.self::ACME.'/platforms*' => Http::response(self::body(self::ACME))]);

        $panel = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk()->getContent());

        $this->assertStringContainsString('href="https://infodot.co.za/home"', $panel);
        $this->assertStringContainsString('href="https://infodot.co.za/go/files?company='.self::ACME.'"', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.co.za/', $href);
        }
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js', 'js/dot-search.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        // Every relative url() in the shared CSS lands on a file that is really there (fonts, arrow mask, stencil face).
        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        // Logos: this site's own copies, so img-src 'self' holds.
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The policy gained no origin: it still names none at all.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: blob:", $policy);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $policy);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('account.home'))->getContent();

        foreach ([$this->pillHtml($html), $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
