<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssertsResponsiveShell;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class ResponsiveShellTest extends TestCase
{
    use AssertsResponsiveShell, BuildsMarketplace, RefreshDatabase;

    public function test_the_public_storefront_has_the_drawer_for_guests(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Guest drawer Walnut Chopping Board']);

        foreach ([route('products.index'), route('products.show', $product), url('/')] as $url) {
            $xpath = $this->assertResponsiveShell($this->get($url));

            $drawer = '//dialog[@id="menu-drawer"]';
            $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('products.index').'"]')->length);
            $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('login').'"]')->length, 'a truthful sign-in link, not a dead sign-up link');
            $this->assertSame(0, $xpath->query('//a[@href="'.route('register').'"]')->length, 'sign-up is closed: nothing links to it');
            $this->assertSame(0, $xpath->query($drawer.'//form[@action="'.route('logout').'"]')->length, 'no sign-out for a guest');
            $this->assertStringContainsString('Accounts come from InfoDot', $xpath->document->textContent);
        }
    }

    public function test_the_product_grid_uses_the_kit_grid_and_cards(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Grid class Soapstone Candle Holder']);

        $xpath = $this->shellXPath($this->get(route('products.index'))->assertOk());

        $this->assertSame(1, $xpath->query('//main//div[contains(@class, "ds-grid")]')->length);
        $this->assertSame(1, $xpath->query('//main//article[contains(@class, "ds-pcard")]')->length);
    }

    public function test_every_buyer_and_seller_page_carries_the_menu_hooks(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Hooked Brass Candlestick Pair']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);

        foreach ([
            [$buyer, route('dashboard'), 'menu-drawer'],
            [$buyer, route('cart.index'), 'menu-drawer'],
            [$buyer, route('orders.index'), 'menu-drawer'],
            [$buyer, route('orders.show', $order), 'menu-drawer'],
            [$buyer, route('seller.store.create'), 'menu-drawer'],
            [$buyer, route('profile.show'), 'menu-drawer'],
            [$seller, route('dashboard'), 'desk-menu'],
            [$seller, route('seller.products.index'), 'desk-menu'],
            [$seller, route('seller.products.create'), 'desk-menu'],
            [$seller, route('seller.orders.index'), 'desk-menu'],
            [$seller, route('seller.promo-codes.index'), 'desk-menu'],
            [$seller, route('seller.store.edit'), 'desk-menu'],
        ] as [$user, $url, $menu]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url), $menu);
        }
    }

    public function test_the_order_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Scrolling Table Copper Whisk']), 1]]);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($buyer)->get(route('orders.index'))), 'Your orders');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($seller)->get(route('dashboard')), 'desk-menu'), 'Recent orders');
    }

    public function test_the_desk_menu_lists_the_seller_desk_for_a_seller_and_the_account_menu_the_buyer_pages(): void
    {
        $seller = $this->makeSeller();
        $xpath = $this->assertResponsiveShell($this->actingAs($seller)->get(route('dashboard')), 'desk-menu');

        foreach (['seller.products.index', 'seller.orders.index', 'seller.promo-codes.index', 'seller.store.edit'] as $name) {
            $this->assertGreaterThan(0, $xpath->query('//dialog[@id="desk-menu"]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertStringContainsString('ds-ctx-seller', $xpath->query('//body')->item(0)->getAttribute('class'));

        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeBuyer())->get(route('dashboard')));
        $this->assertStringContainsString('ds-ctx-account', $xpath->query('//body')->item(0)->getAttribute('class'));
        $this->assertGreaterThan(0, $xpath->query('//dialog[@id="account-menu-sheet"]//a[@href="'.route('orders.index').'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('seller.products.index').'"]')->length, 'a buyer is not shown the seller desk');
    }

    public function test_the_account_menu_has_sign_out_and_a_team_switcher(): void
    {
        $buyer = $this->makeBuyer();
        $second = Team::factory()->create(['user_id' => $buyer->id, 'personal_team' => false, 'name' => 'Karoo Trading Collective']);

        $xpath = $this->assertResponsiveShell($this->actingAs($buyer)->get(route('dashboard')));

        $menu = '//div[@id="account-menu"]';
        $this->assertSame(1, $xpath->query($menu.'//form[@action="'.route('logout').'"]//button')->length);
        $this->assertSame(1, $xpath->query($menu.'//a[@href="'.route('teams.show', $buyer->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($menu.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($menu.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
    }

    public function test_the_notification_panel_opens_as_a_labelled_disclosure(): void
    {
        Livewire::actingAs($this->makeBuyer())->test(NotificationBell::class)
            ->assertSeeHtml('aria-label="Notifications"')
            ->assertSeeHtml('aria-expanded="false"')
            ->assertDontSeeHtml('aria-label="Notifications" aria-hidden')
            ->set('open', true)
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('role="region" aria-label="Notifications"');
    }
}
