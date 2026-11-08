<?php

namespace Tests\Feature\Polish;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

/**
 * R-43 polish, the chrome agent (D-180 to D-189): wordmark, header, home rhythm, store page, error pages, empty states, sign-in, buttons.
 * Each test pins a defect the outside jury found, so it cannot return.
 */
class ChromePolishTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    public function test_the_wordmark_is_live_heavy_type_and_not_the_thin_picture(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="ds-logo__emall"', $html);
        $this->assertStringContainsString('class="ds-logo__stop"', $html);
        $this->assertStringNotContainsString('emall-wordmark', $html, 'the thin wordmark picture read "email" at reading size');
        $css = file_get_contents(base_path('resources/css/shell.css'));
        $this->assertMatchesRegularExpression('/\.ds-logo__word\s*\{[^}]*font-weight:\s*800/', $css);
    }

    public function test_the_cart_count_is_not_faded_in_on_first_paint(): void
    {
        $css = file_get_contents(base_path('resources/css/shell.css'));

        $this->assertDoesNotMatchRegularExpression('/\.ds-count\s*\{[^}]*animation:\s*ds-stamp/', $css, 'the count must be drawn in place; only .is-stamped animates');
        $this->assertStringContainsString('.ds-count.is-stamped', $css);
    }

    public function test_the_header_offers_a_search_icon_for_the_compact_phone_header(): void
    {
        $this->get('/')->assertOk()->assertSee('data-search-reveal', false);
    }

    public function test_a_primary_button_with_an_arrow_carries_the_stamp_hooks(): void
    {
        $go = Blade::render('<x-ds.button href="/x" iconRight="arrow-right">Go</x-ds.button>');
        $lead = Blade::render('<x-ds.button icon="search" type="submit">Find</x-ds.button>');

        $this->assertStringContainsString('ds-btn--go', $go);
        $this->assertStringContainsString('ds-btn--lead', $lead);
        $css = file_get_contents(base_path('resources/css/ds-core.css'));
        $this->assertMatchesRegularExpression('/@media \(hover: hover\) and \(prefers-reduced-motion: no-preference\)\s*\{[^}]*\.ds-btn--primary[^}]*translateY\(-1px\)/s', $css);
    }

    public function test_the_home_page_no_longer_repeats_what_the_header_already_offers(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Home Kettle']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('See sellers near you', $html);
        $this->assertStringNotContainsString('id="sell-h"', $html);
        $this->assertSame(1, substr_count($html, 'Ask Emall</span>'), 'one Ask Emall button, in the ask row');
        $this->assertStringContainsString('home-ask', $html);
    }

    public function test_a_missing_address_renders_in_the_storefront_layout_for_a_guest_with_search_and_categories(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Anything']);

        $html = $this->get('/definitely/not/here')->assertNotFound()->assertSee("We couldn't find that page")->assertSee('the rest of the Dot Ecosystem', false)->getContent();

        $this->assertStringContainsString('class="ds-header"', $html, 'the real header');
        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('id="err-q"', $html);
        $this->assertStringContainsString(route('login'), $html);
    }

    public function test_a_missing_address_reads_the_session_so_a_signed_in_person_is_not_offered_sign_in(): void
    {
        $buyer = $this->makeBuyer();

        $html = $this->actingAs($buyer)->get('/definitely/not/here')->assertNotFound()->getContent();

        $this->assertStringContainsString('Account overview', $html, 'the account menu of the person looking at the page');
        $this->assertStringContainsString('Your orders', $html);
        $this->assertStringNotContainsString('>Sign in</span>', $html);
    }

    public function test_the_500_and_503_pages_stay_standalone_and_never_touch_the_session_or_database(): void
    {
        foreach ([500, 503] as $code) {
            $html = view("errors.{$code}")->render();
            $this->assertStringNotContainsString('class="ds-header"', $html);
            $this->assertStringContainsString('ds-auth', $html);
        }
    }

    public function test_the_rich_error_falls_back_to_the_standalone_page_when_the_layout_cannot_render(): void
    {
        // A category tree that throws is swallowed by Navigation, so break the layout itself: the view the layout includes is shadowed by one that throws.
        $dir = sys_get_temp_dir().'/emall-bad-header-'.uniqid();
        mkdir($dir.'/layouts/partials', 0777, true);
        file_put_contents($dir.'/layouts/partials/store-header.blade.php', '@php throw new \RuntimeException("header down"); @endphp');
        view()->getFinder()->prependLocation($dir);

        $html = view('errors.404')->render();

        $this->assertStringContainsString('We couldn&#039;t find that page', $html);
        $this->assertStringContainsString('ds-auth', $html, 'the standalone frame');
        $this->assertStringNotContainsString('header down', $html);
    }

    public function test_the_empty_state_is_a_ticket_stub_whose_punch_carries_the_states_own_glyph(): void
    {
        $cart = Blade::render('<x-ds.empty-state title="Nothing here" icon="cart">Say why.<x-slot:actions><x-ds.button href="/">Go</x-ds.button></x-slot:actions></x-ds.empty-state>');
        $heart = Blade::render('<x-ds.empty-state title="Nothing here" icon="heart">Say why.</x-ds.empty-state>');

        $this->assertStringContainsString('ds-empty__stub', $cart);
        $this->assertStringContainsString('ds-empty__punch--glyph', $cart);
        $this->assertStringContainsString('Nothing here', $cart);
        $this->assertSame(1, substr_count(explode('ds-empty__text', $cart)[0], '<svg'), 'one glyph in the punch, before the words');
        $this->assertNotSame(preg_replace('/\s+/', '', explode('ds-empty__text', $cart)[0]), preg_replace('/\s+/', '', explode('ds-empty__text', $heart)[0]), 'a cart and a wishlist do not share one picture');
    }

    public function test_an_empty_state_with_no_icon_or_an_unknown_one_keeps_the_empty_ring_and_never_the_fallback_square(): void
    {
        foreach (['<x-ds.empty-state title="Nothing here">Why.</x-ds.empty-state>', '<x-ds.empty-state title="Nothing here" icon="no-such-glyph-xyz">Why.</x-ds.empty-state>'] as $template) {
            $html = Blade::render($template);

            $this->assertStringContainsString('ds-empty__punch', $html);
            $this->assertStringNotContainsString('ds-empty__punch--glyph', $html);
            $this->assertSame(0, substr_count(explode('ds-empty__text', $html)[0], '<svg'));
        }
    }

    public function test_the_account_pages_do_not_repeat_their_navigation_in_a_top_strip(): void
    {
        $html = $this->actingAs($this->makeBuyer())->get(route('orders.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="ds-subnav"', $html);
        $this->assertStringContainsString('class="ds-bottombar"', $html, 'the bottom bar stays');
        $this->assertStringContainsString('class="ds-account__rail"', $html, 'and so does the desktop rail');
    }

    public function test_the_store_page_puts_products_before_the_trust_ladder_and_summarises_it(): void
    {
        $seller = $this->makeSeller(['name' => 'Order Test Store']);
        $this->makeListing($seller, ['name' => 'Chrome Bowl', 'stock' => 3]);

        $html = $this->get(route('stores.show', $seller->store))->assertOk()->getContent();

        $this->assertStringContainsString('class="sh-how"', $html);
        $this->assertStringContainsString('How this is measured', $html);
        $this->assertMatchesRegularExpression('/checked by InfoDot, \d+ claimed by the seller, \d+ measures? from orders on Emall/', $html);
        $this->assertLessThan(strpos($html, 'id="products"'), strpos($html, 'class="sh-how"'), 'the summary line is above the products');
        $this->assertGreaterThan(strpos($html, 'id="products"'), strpos($html, 'id="delivery-returns"'), 'delivery and returns follow the products');
        $this->assertGreaterThan(strpos($html, 'id="products"'), strpos($html, 'id="store-reviews"'));
    }

    public function test_sign_in_is_one_ticket_with_a_primary_infodot_half_and_a_password_reveal(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('class="ds-signin"', $html);
        $this->assertStringContainsString('data-reveal="password"', $html);
        $this->assertStringContainsString('aria-label="Show password"', $html);
        $this->assertStringContainsString('Forgot your password?', $html);
        $this->assertStringContainsString('ds-btn--quiet', $html);
        $this->assertSame('', $this->passwordValueOnLogin($html), 'a password field never carries a value');
    }

    private function passwordValueOnLogin(string $html): string
    {
        preg_match('/<input id="password"[^>]*value="([^"]*)"/', $html, $m);

        return $m[1] ?? 'missing';
    }
}
