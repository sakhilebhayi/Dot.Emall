<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Team;
use App\Models\User;
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

            $drawer = '//div[@id="dh-drawer"]';
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
            [$buyer, route('account.home')],
            [$buyer, route('cart.index')],
            [$buyer, route('orders.index')],
            [$buyer, route('orders.show', $order)],
            [$buyer, route('seller.store.create')],
            [$buyer, route('profile.show')],
            [$seller, route('seller.dashboard')],
            [$seller, route('seller.products.index')],
            [$seller, route('seller.products.create')],
            [$seller, route('seller.orders.index')],
            [$seller, route('seller.promo-codes.index')],
            [$seller, route('seller.store.edit')],
        ] as [$user, $url]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url));
        }
    }

    public function test_no_account_menu_offers_teams_but_the_team_routes_still_answer(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);

        foreach ([[$buyer, route('account.home')], [$buyer, route('cart.index')], [$seller, route('seller.dashboard')], [$operator, route('operator.products.index')]] as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            foreach (['Team settings', 'Create a team', 'Switch team', route('teams.create'), '/teams/'.$user->currentTeam?->id] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "a marketplace has no teams: {$needle} on {$url}");
            }
        }

        // Unlinked, not removed: the Jetstream routes (and the SSO team sync behind them) are untouched.
        $this->actingAs($buyer)->get(route('teams.create'))->assertOk();
    }

    public function test_the_order_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Scrolling Table Copper Whisk']), 1]]);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($buyer)->get(route('orders.index'))), 'Your orders');
        // The seller's orders page (W6) is a list of parcels, not a table: it wraps at any width and has nothing to scroll sideways.
        $list = $this->assertResponsiveShell($this->actingAs($seller)->get(route('seller.orders.index')));
        $this->assertSame(0, $list->query('//main//table')->length, 'the parcel list is not a table');
        $this->assertSame(1, $list->query('//main//ul[contains(@class, "sd-list")]//a[contains(@href, "/sell/orders/")][contains(., "Order ")]')->length, 'the parcel is listed with a link to its page');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($seller)->get(route('seller.dashboard'))), 'Recent orders');
    }

    public function test_the_desk_menu_lists_the_seller_desk_for_a_seller_and_the_account_menu_the_buyer_pages(): void
    {
        $seller = $this->makeSeller();
        $xpath = $this->assertResponsiveShell($this->actingAs($seller)->get(route('seller.dashboard')));

        foreach (['seller.products.index', 'seller.orders.index', 'seller.promo-codes.index', 'seller.store.edit'] as $name) {
            $this->assertGreaterThan(0, $xpath->query('//div[@id="dh-drawer"]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertStringContainsString('ds-ctx-seller', $xpath->query('//body')->item(0)->getAttribute('class'));

        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeBuyer())->get(route('account.home')));
        $this->assertStringContainsString('ds-ctx-account', $xpath->query('//body')->item(0)->getAttribute('class'));
        $this->assertGreaterThan(0, $xpath->query('//div[@id="dh-drawer"]//a[@href="'.route('orders.index').'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('seller.products.index').'"]')->length, 'a buyer is not shown the seller desk');
    }

    public function test_the_account_menu_has_sign_out_and_no_team_entries_even_for_a_person_in_two_teams(): void
    {
        $buyer = $this->makeBuyer();
        Team::factory()->create(['user_id' => $buyer->id, 'personal_team' => false, 'name' => 'Karoo Trading Collective']);

        $xpath = $this->assertResponsiveShell($this->actingAs($buyer)->get(route('account.home')));

        $menu = '//div[@id="dh-account-menu"]';
        $this->assertSame(1, $xpath->query($menu.'//form[@action="'.route('logout').'"]//button')->length);
        $this->assertSame(0, $xpath->query($menu.'//a[contains(@href, "/teams/")]')->length);
        $this->assertSame(0, $xpath->query($menu.'//form[@action="'.route('current-team.update').'"]')->length);
    }

    public function test_the_notification_bell_is_dot_uis_panel_bell_and_loads_its_list_when_first_opened(): void
    {
        Livewire::actingAs($this->makeBuyer())->test(NotificationBell::class)
            ->assertSeeHtml('aria-label="Notifications"')
            ->assertSeeHtml('aria-expanded="false"')
            ->assertSeeHtml('data-dh-bell')
            ->assertSeeHtml('role="region" aria-label="Notifications"')
            ->assertSet('loaded', false)
            ->assertSee('Loading')
            ->call('load')
            ->assertSet('loaded', true)
            ->assertSee('No notifications yet');
    }
}
