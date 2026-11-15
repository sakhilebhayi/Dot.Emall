<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Support\HeaderNav;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * R-52: Roboto is the one text face (self-hosted, from the shared dot-ui), every layout context carries the shared dot-ui header with its search and a
 * phone menu, nothing external or inline is loaded, and the header's structure is the one this platform documents (DESIGN.md "R-52").
 */
class RobotoAndHeaderTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    // ── Roboto ─────────────────────────────────────────────────────────────

    public function test_roboto_is_declared_and_its_files_are_served_from_this_origin(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="dot-roboto"', $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-type.css'), $html);
        $this->assertStringContainsString('rel="preload" as="font" type="font/woff2" href="'.asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2').'" crossorigin', $html);

        $css = (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $css);
        $this->assertStringContainsString('font-display: swap', $css);
        foreach (['roboto-latin-wght-normal.woff2', 'roboto-latin-ext-wght-normal.woff2', 'OFL.txt'] as $file) {
            $this->assertFileExists(public_path('dot-ui/fonts/roboto/'.$file));
        }
    }

    public function test_the_text_family_token_is_roboto_and_no_other_text_face_is_declared(): void
    {
        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertMatchesRegularExpression('/--font-text:\s*var\(--dot-font-sans/', $tokens);

        $fonts = (string) file_get_contents(resource_path('css/fonts.css'));
        preg_match_all('/font-family:\s*"([^"]+)"/', $fonts, $m);
        $this->assertEqualsCanonicalizing(['Martian Mono', 'Martian Mono Fallback'], array_values(array_unique($m[1])), 'only the mono face for codes remains');

        $all = '';
        foreach (array_merge(glob(resource_path('css/*.css')), glob(resource_path('views/**/*.blade.php')), glob(resource_path('views/*/*/*.blade.php')), glob(resource_path('views/*/*.blade.php'))) as $file) {
            $all .= (string) file_get_contents($file);
        }
        foreach (['Schibsted', 'Archivo', 'Big Shoulders', 'fonts.googleapis.com', 'fonts.gstatic.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $all, "{$needle} is gone from the styles and views");
        }
        $this->assertFileDoesNotExist(public_path('fonts/schibsted-grotesk-var-latin.woff2'));
        $this->assertSame([], glob(public_path('dot-ui/switcher-fonts/*')) ?: [], 'the stencil face is not distributed any more');
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-fonts.css'));
    }

    public function test_tailwind_takes_the_shared_type_preset(): void
    {
        $config = (string) file_get_contents(base_path('tailwind.config.js'));

        $this->assertStringContainsString("import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs'", $config);
        $this->assertStringContainsString('presets: [dotPreset]', $config);
        $this->assertStringNotContainsString('--font-text', $config, 'no host font override beside the preset');
        $this->assertFileExists(base_path('dot-ui/tailwind/tailwind-preset.cjs'));
    }

    public function test_every_context_loads_only_same_origin_assets_and_no_inline_script_or_style(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $operator = $this->makeOperator();

        $pages = [
            [null, '/'], [null, route('login')], [null, route('cart.index')], [null, '/not/here'],
            [$buyer, route('account.home')], [$seller, route('seller.dashboard')], [$operator, route('operator.home')],
        ];

        foreach ($pages as [$user, $url]) {
            $response = $user ? $this->actingAs($user)->get($url) : $this->get($url);
            $html = $response->getContent();

            preg_match_all('/<(?:link|script)[^>]+(?:href|src)="([^"]+)"/', $html, $refs);
            foreach ($refs[1] as $ref) {
                $host = parse_url($ref, PHP_URL_HOST);
                $this->assertTrue($host === null || $host === parse_url(asset('/'), PHP_URL_HOST), "{$url} loads {$ref} from another origin");
            }
            $header = $this->headerHtml($html);
            $this->assertStringNotContainsString(' style=', $header, "{$url}: inline style in the header");
            $this->assertStringNotContainsString('<script', $header, "{$url}: inline script in the header");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', explode('</head>', $html)[0], "{$url}: no inline script in the head (the theme boot is an external file)");
        }
    }

    // ── The header on every page type ──────────────────────────────────────

    /** The markup of the header only. */
    private function headerHtml(string $html): string
    {
        $this->assertSame(1, preg_match('#<header class="[^"]*\bdh\b.*?</header>#s', $html, $m), 'the page carries the shared header');

        return $m[0];
    }

    public function test_every_page_type_carries_the_shared_header_and_a_search(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $operator = $this->makeOperator();
        $product = $this->makeListing($seller, ['name' => 'Header Search Kettle']);

        $pages = [
            'storefront' => [null, '/'], 'catalogue' => [null, route('products.index')], 'product' => [null, route('products.show', $product)], 'cart' => [null, route('cart.index')],
            'help' => [null, route('help.index')], 'sign in' => [null, route('login')], 'legal' => [null, route('terms.show')], '404' => [null, '/no/such/page'],
            'account' => [$buyer, route('account.home')], 'orders' => [$buyer, route('orders.index')], 'profile' => [$buyer, route('profile.show')],
            'seller desk' => [$seller, route('seller.dashboard')], 'seller products' => [$seller, route('seller.products.index')], 'operator' => [$operator, route('operator.home')],
        ];

        foreach ($pages as $name => [$user, $url]) {
            $html = ($user ? $this->actingAs($user)->get($url) : $this->get($url))->getContent();
            $header = $this->headerHtml($html);

            $this->assertMatchesRegularExpression('/<div class="dsr[^"]*"/', $header, "{$name}: the shared search pill is in the header");
            $this->assertStringContainsString('role="search"', $header, $name);
            $this->assertStringContainsString('data-dh-menu-button', $header, "{$name}: a phone menu button");
            $this->assertStringContainsString('data-dh-search-toggle', $header, "{$name}: the phone search sheet button");
            $this->assertStringContainsString('id="dh-drawer"', $html, "{$name}: the drawer is printed");
        }
    }

    public function test_the_standalone_error_pages_have_the_bar_and_the_search_but_read_no_session(): void
    {
        foreach ([500, 503] as $code) {
            $html = view("errors.{$code}")->render();

            $this->assertStringContainsString('class="dot-roboto"', $html);
            $this->assertStringContainsString('dot-ui/css/dot-roboto.css', $html);
            $this->assertStringContainsString('role="search"', $html);
            $this->assertStringContainsString('action="'.url('/search').'"', $html, 'a plain GET form to the shop search');
        }
    }

    public function test_the_storefront_bar_is_one_bar_with_four_primary_items_and_no_category_row(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Bar Kettle']);

        $html = $this->get('/')->assertOk()->getContent();
        $header = $this->headerHtml($html);

        $this->assertStringNotContainsString('ds-catnav', $html, 'the second category row is gone');
        $this->assertStringNotContainsString('ds-mega', $html);
        $this->assertStringNotContainsString('class="ds-util"><div class="ds-container ds-util__row"><div class="ds-util__loc"></div>', $html);
        $this->assertSame(1, preg_match('#<ul class="dh-nav__list">(.*?)</ul>#s', $header, $m));
        $labels = array_map('trim', array_map('strip_tags', preg_split('#</li>#', $m[1]) ?: []));
        $labels = array_values(array_filter($labels, fn ($l) => $l !== '' && ! str_starts_with($l, 'More')));
        $first = array_map(fn ($l) => explode(' ', trim(preg_replace('/\s+/', ' ', $l)))[0], $labels);
        $this->assertContains($first[0], ['Categories', 'All'], 'a Categories dropdown, or All products while the catalogue has no categories');
        $this->assertSame(['Stores', 'Sell', 'Help'], array_slice($first, 1));
    }

    public function test_the_nav_model_groups_categories_and_keeps_help_and_selling_reachable(): void
    {
        $nav = HeaderNav::store([['name' => 'Kitchen', 'href' => '/c/kitchen', 'children' => [['name' => 'Pans', 'href' => '/c/pans']]]]);

        $this->assertSame(['Categories', 'Stores', 'Sell', 'Help'], array_column($nav, 'label'));
        $this->assertSame(['All products', 'Kitchen'], array_column($nav[0]['items'], 'label'));
        $this->assertSame(['All products', 'Stores', 'Sell', 'Help'], array_column(HeaderNav::store([]), 'label'), 'with no categories there is no empty dropdown');
    }

    public function test_the_signed_in_account_menu_keeps_every_link_and_adds_the_theme_and_sign_out(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $operator = $this->makeOperator();

        $html = $this->actingAs($buyer)->get(route('account.home'))->getContent();
        foreach (['Account overview', 'Orders', 'Wishlist', 'Sell on Emall', 'Profile and security', 'Sign out'] as $label) {
            $this->assertStringContainsString($label, $this->headerHtml($html), $label);
        }
        $this->assertStringContainsString('data-dh-theme-set="dark"', $html);
        $this->assertStringNotContainsString('Seller desk', $this->headerHtml($html));
        $this->assertStringContainsString('Seller desk', $this->headerHtml($this->actingAs($seller)->get(route('account.home'))->getContent()));
        $this->assertStringContainsString('Operator area', $this->headerHtml($this->actingAs($operator)->get(route('account.home'))->getContent()));
    }

    public function test_the_phone_drawer_keeps_every_sub_category_and_the_account_pages(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Drawer Kettle']);
        $buyer = $this->makeBuyer();
        $categories = Navigation::categories();

        $html = $this->actingAs($buyer)->get(route('account.home'))->getContent();
        preg_match('#<div class="dh-drawer" id="dh-drawer".*?</div>\s*</div>\s*</div>#s', $html, $m);
        $drawer = $m[0] ?? '';
        $this->assertNotSame('', $drawer);
        foreach ($categories as $c) {
            $this->assertStringContainsString('All in '.$c['name'], $drawer);
            foreach ($c['children'] as $k) {
                $this->assertStringContainsString($k['name'], $drawer);
            }
        }
        foreach (['Overview', 'Orders', 'Returns', 'Wishlist', 'Saved searches', 'Addresses', 'Alerts', 'Profile and security'] as $label) {
            $this->assertStringContainsString($label, $drawer, "the account page {$label} is in the drawer");
        }
    }

    public function test_the_phone_bottom_bar_has_at_most_five_targets_and_its_more_opens_the_header_drawer(): void
    {
        $seller = $this->makeSeller();

        foreach ([[$this->makeBuyer(), route('account.home')], [$seller, route('seller.dashboard')]] as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->getContent();
            $this->assertSame(1, preg_match('#<nav class="ds-bottombar".*?</nav>#s', $html, $m), $url);
            $this->assertLessThanOrEqual(5, substr_count($m[0], '<li>'));
            $this->assertStringContainsString('data-dh-menu-button aria-controls="dh-drawer"', $m[0], 'More opens the drawer, no second menu');
        }
    }

    public function test_the_desk_header_names_its_context_and_has_no_primary_nav_because_the_rail_is_the_nav(): void
    {
        $html = $this->actingAs($this->makeSeller())->get(route('seller.dashboard'))->getContent();
        $header = $this->headerHtml($html);

        $this->assertStringContainsString('Seller desk', $header);
        $this->assertStringContainsString('dh--nonav', $header);
        $this->assertStringContainsString('data-dsw', $header, 'the platform switcher stays in the desk bar');
        $this->assertStringContainsString('data-dh-bell', $header, 'and the notification bell');
    }

    public function test_the_notification_bell_and_the_switcher_are_in_the_signed_in_account_header(): void
    {
        $buyer = $this->makeBuyer();
        $team = Team::forceCreate(['user_id' => $buyer->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => null]);
        $buyer->forceFill(['current_team_id' => $team->id])->save();

        $header = $this->headerHtml($this->actingAs($buyer->fresh())->get(route('account.home'))->getContent());

        $this->assertStringContainsString('data-dh-bell', $header);
        $this->assertStringContainsString('data-dsw', $header);
        $this->assertStringContainsString('ds-cart-link', $header);
    }

    // ── Theme ──────────────────────────────────────────────────────────────

    public function test_the_theme_choice_is_dot_uis_with_emalls_key_and_the_css_follows_data_theme(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('data-dh-theme-key="emall-theme"', $html);
        $this->assertStringContainsString('dot-ui/js/dot-theme-boot.js', $html);
        $this->assertStringNotContainsString("localStorage.getItem('emall-theme')", $html, 'the inline first-paint script is gone');
        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString(':root[data-theme="light"] { color-scheme: light; }', $tokens);
    }
}
