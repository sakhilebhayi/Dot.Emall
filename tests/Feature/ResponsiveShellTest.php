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

        foreach ([route('products.index'), route('products.show', $product)] as $url) {
            $xpath = $this->assertResponsiveShell($this->get($url));

            $drawer = '//aside[@id="app-sidebar"]';
            $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('products.index').'"]')->length);
            $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('login').'"]')->length);
            $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('register').'"]')->length);
            $this->assertSame(0, $xpath->query($drawer.'//form[@action="'.route('logout').'"]')->length, 'no sign-out for a guest');
            $this->assertSame(0, $xpath->query('//details[contains(@class, "team-switch")]')->length, 'no team switcher for a guest');
            $this->assertSame(0, $xpath->query('//div[contains(@class, "shell-legacy-nav")]')->length);
        }
    }

    public function test_the_product_grid_is_a_class_the_phone_layout_turns_into_two_columns(): void
    {
        $this->makeListing($this->makeSeller(), ['name' => 'Grid class Soapstone Candle Holder']);

        $xpath = $this->shellXPath($this->get(route('products.index'))->assertOk());

        $this->assertSame(1, $xpath->query('//main//div[contains(@class, "product-grid")]')->length);
    }

    public function test_every_buyer_and_seller_page_carries_the_drawer_hooks(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Hooked Brass Candlestick Pair']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);

        foreach ([
            [$buyer, route('dashboard')],
            [$buyer, route('cart.index')],
            [$buyer, route('orders.index')],
            [$buyer, route('orders.show', $order)],
            [$buyer, route('seller.store.create')],
            [$buyer, route('profile.show')],
            [$seller, route('dashboard')],
            [$seller, route('seller.products.index')],
            [$seller, route('seller.products.create')],
            [$seller, route('seller.orders.index')],
            [$seller, route('seller.promo-codes.index')],
            [$seller, route('seller.store.edit')],
        ] as [$user, $url]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url));
        }
    }

    public function test_the_order_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Scrolling Table Copper Whisk']), 1]]);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($buyer)->get(route('orders.index'))), 'Your orders');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($seller)->get(route('seller.orders.index'))), 'Orders to fulfil');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($seller)->get(route('dashboard'))), 'Recent orders');
    }

    public function test_the_drawer_lists_the_seller_desk_for_a_seller_and_the_invitation_for_a_buyer(): void
    {
        $seller = $this->makeSeller();
        $xpath = $this->assertResponsiveShell($this->actingAs($seller)->get(route('dashboard')));

        foreach (['seller.products.index', 'seller.orders.index', 'seller.promo-codes.index', 'seller.store.edit', 'cart.index', 'orders.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }

        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeBuyer())->get(route('dashboard')));
        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('seller.store.create').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('seller.products.index').'"]')->length);
    }

    public function test_the_drawer_footer_has_sign_out_and_a_team_switcher(): void
    {
        $buyer = $this->makeBuyer();
        $second = Team::factory()->create(['user_id' => $buyer->id, 'personal_team' => false, 'name' => 'Karoo Trading Collective']);

        $xpath = $this->assertResponsiveShell($this->actingAs($buyer)->get(route('dashboard')));

        $footer = '//aside[@id="app-sidebar"]//div[contains(@class, "sidebar-footer")]';
        $this->assertSame(1, $xpath->query($footer.'//form[@action="'.route('logout').'"]//button[@aria-label="Sign out"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//details[contains(@class, "team-switch")]/summary')->length);
        $this->assertSame(1, $xpath->query($footer.'//a[@href="'.route('teams.show', $buyer->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($footer.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
    }

    public function test_the_notification_panel_is_a_class_the_phone_layout_can_pin_inside_the_viewport(): void
    {
        Livewire::actingAs($this->makeBuyer())->test(NotificationBell::class)
            ->assertDontSeeHtml('notif-panel')
            ->assertSeeHtml('aria-label="Notifications"')
            ->set('open', true)
            ->assertSeeHtml('class="notif-panel"');
    }
}
