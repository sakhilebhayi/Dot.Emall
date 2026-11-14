<?php

namespace Tests\Feature\Shop;

use App\Models\Address;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationPreferences;
use App\Support\Landing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

class AccountAndHelpTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsShopFlows;
    use RefreshDatabase;

    // ---- the overview and the doorway -------------------------------------------------------------------------------------

    public function test_the_account_pages_all_need_a_signed_in_person(): void
    {
        foreach (['account.home', 'account.returns', 'account.addresses', 'account.notifications', 'account.privacy', 'account.privacy.export', 'orders.index'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_dashboard_is_the_landing_and_links_on_to_each_roles_own_home(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller)->get('/dashboard')->assertOk()->assertViewIs('seller.dashboard')->assertSee(route('seller.dashboard'), false);
        $this->actingAs($this->makeBuyer())->get('/dashboard')->assertOk()->assertViewIs('account.dashboard')->assertSee(route('account.home'), false);
    }

    public function test_the_overview_is_a_buyers_page_with_orders_returns_wishlist_and_address(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $this->enablePaystack();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Overview Kettle', 'stock' => 5]), 1]]);
        Address::create(['user_id' => $buyer->id, 'is_default_shipping' => true] + $this->address(['recipient_name' => 'Overview Person']));

        $this->actingAs($buyer)->get(route('account.home'))->assertOk()->assertViewIs('account.dashboard')
            ->assertSee('Welcome, '.Landing::firstName($buyer).'.')->assertSee($order->reference())->assertSee('1 order is waiting for payment')->assertSee('Overview Person')
            ->assertSee('Download or delete')->assertDontSee('Open your seller desk')->assertDontSee('Revenue');

        $this->actingAs($seller)->get(route('account.home'))->assertSee('Open your seller desk');
    }

    // ---- addresses --------------------------------------------------------------------------------------------------------

    public function test_the_address_book_adds_edits_defaults_and_deletes_with_south_african_validation(): void
    {
        $user = $this->makeBuyer();

        $this->actingAs($user)->post(route('account.addresses.store'), $this->address(['province' => 'Narnia', 'postal_code' => '12']))->assertSessionHasErrors(['province', 'postal_code']);
        $this->assertSame(0, Address::count());

        $this->actingAs($user)->post(route('account.addresses.store'), $this->address(['label' => 'Home']))->assertRedirect(route('account.addresses'));
        $this->actingAs($user)->post(route('account.addresses.store'), $this->address(['label' => 'Work', 'line1' => '2 Office Park']))->assertRedirect(route('account.addresses'));
        [$home, $work] = Address::orderBy('id')->get()->all();
        $this->assertTrue($home->is_default_shipping, 'the first address is the default');

        $this->actingAs($user)->post(route('account.addresses.default', $work))->assertRedirect();
        $this->assertTrue($work->fresh()->is_default_shipping);
        $this->assertFalse($home->fresh()->is_default_shipping);

        $this->actingAs($user)->get(route('account.addresses.edit', $home))->assertOk()->assertSee('Edit this address')->assertSee('14 Protea Lane');
        $this->actingAs($user)->patch(route('account.addresses.update', $home), $this->address(['line1' => '99 Changed Avenue']))->assertRedirect(route('account.addresses'));
        $this->actingAs($user)->get(route('account.addresses'))->assertSee('99 Changed Avenue')->assertSee('Default');

        $this->actingAs($user)->delete(route('account.addresses.destroy', $work))->assertRedirect(route('account.addresses'));
        $this->assertTrue($home->fresh()->is_default_shipping, 'the default is handed on');
    }

    public function test_nobody_can_read_change_or_delete_another_persons_address(): void
    {
        $owner = $this->makeBuyer();
        $address = Address::create(['user_id' => $owner->id] + $this->address());
        $other = $this->makeBuyer();

        $this->actingAs($other)->get(route('account.addresses.edit', $address))->assertForbidden();
        $this->actingAs($other)->patch(route('account.addresses.update', $address), $this->address(['line1' => 'Hijacked']))->assertForbidden();
        $this->actingAs($other)->delete(route('account.addresses.destroy', $address))->assertForbidden();
        $this->actingAs($other)->post(route('account.addresses.default', $address))->assertForbidden();
        $this->actingAs($other)->get(route('account.addresses'))->assertDontSee('14 Protea Lane');

        $this->assertSame('14 Protea Lane', $address->fresh()->line1);
    }

    // ---- notification choices ---------------------------------------------------------------------------------------------

    public function test_transactional_notices_are_locked_on_and_alerts_and_marketing_follow_the_choice(): void
    {
        $user = $this->makeBuyer();
        $prefs = app(NotificationPreferences::class);

        $this->actingAs($user)->get(route('account.notifications'))->assertOk()->assertSee('cannot be turned off')->assertSee('Off until you say yes');
        $this->assertSame(['database'], $prefs->channels($user, 'alerts'));
        $this->assertSame([], $prefs->channels($user, 'marketing'));

        $this->actingAs($user)->put(route('account.notifications.update'), ['alerts_mail' => '1', 'transactional_mail' => '0', 'transactional_in_app' => '0'])->assertRedirect(route('account.notifications'));

        $this->assertContains('mail', $prefs->channels($user, 'alerts'));
        $this->assertNotContains('database', $prefs->channels($user, 'alerts'), 'in-app was left unticked');
        $this->assertSame([], $prefs->channels($user, 'marketing'), 'marketing stays off');
        $this->assertSame(['database', 'mail'], $prefs->channels($user, 'transactional'), 'a form cannot switch the order notices off');
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $user->id, 'category' => 'transactional']);
        $this->assertSame(2, NotificationPreference::where('user_id', $user->id)->count());
    }

    // ---- privacy ----------------------------------------------------------------------------------------------------------

    public function test_the_data_export_is_json_of_my_own_data_only(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $me = $this->makeBuyer();
        $them = $this->makeBuyer();
        $this->placeOrder($me, [[$this->makeListing($seller, ['name' => 'Export Mine Kettle', 'stock' => 5]), 1]]);
        $this->placeOrder($them, [[$this->makeListing($seller, ['name' => 'Export Theirs Secret Item', 'stock' => 5]), 1]]);
        Address::create(['user_id' => $them->id] + $this->address(['line1' => '1 Their Secret Road']));

        $response = $this->actingAs($me)->get(route('account.privacy.export'))->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('attachment; filename="dot-emall-my-data-', $response->headers->get('Content-Disposition'));
        $json = $response->json();

        $this->assertSame($me->email, $json['profile']['email']);
        $this->assertSame('Export Mine Kettle', $json['orders'][0]['items'][0]['name']);
        $raw = $response->getContent();
        $this->assertStringNotContainsString('Export Theirs Secret Item', $raw);
        $this->assertStringNotContainsString('1 Their Secret Road', $raw);
        $this->assertStringNotContainsString($them->email, $raw);
        $this->assertStringNotContainsString('password', strtolower($raw));
    }

    public function test_the_privacy_page_says_why_deletion_is_refused_and_otherwise_points_at_the_profile(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->get(route('account.privacy'))->assertOk()->assertSee('Go to account deletion')->assertDontSee('You cannot delete your account yet');

        $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 5]), 1]]);
        $this->actingAs($buyer)->get(route('account.privacy'))->assertSee('You cannot delete your account yet')->assertSee('has not reached you yet');
    }

    // ---- help -------------------------------------------------------------------------------------------------------------

    public function test_every_help_page_renders_with_real_numbers_from_configuration(): void
    {
        config(['emall.returns.window_days' => 9, 'emall.returns.seller_response_days' => 12]);

        $this->get(route('help.index'))->assertOk()->assertSee('How buying works')->assertSee('within 9 days of delivery');
        $this->get(route('help.delivery'))->assertOk()->assertSee('not a promise')->assertSee('from the seller');
        $this->get(route('help.returns'))->assertOk()->assertSee('within 9 days')->assertSee('A seller has 12 days to answer')->assertSee('The seller pays')->assertSee('You pay');
        $this->get(route('help.sellers'))->assertOk()->assertSee('Dot.Emall does not pay out automatically yet')->assertSee('Sign-up is closed');
    }

    public function test_the_contact_page_invents_no_address_and_shows_one_only_when_configured(): void
    {
        config(['emall.support_email' => '']);
        $page = $this->get(route('help.contact'))->assertOk()->assertSee('There is no general support e-mail address or phone number published yet')->assertSee('Report a problem');
        $this->assertDoesNotMatchRegularExpression('/mailto:|\+27\s?\d|0\d{2}[\s-]?\d{3}[\s-]?\d{4}/', $page->getContent());

        config(['emall.support_email' => 'help@example.test']);
        $this->get(route('help.contact'))->assertSee('mailto:help@example.test', false)->assertDontSee('There is no general support e-mail');
    }

    public function test_the_checkout_and_footer_link_to_help_pages_that_exist(): void
    {
        $this->get(route('help.index'))->assertDontSee('data-pending-route="help.', false);
    }

    public function test_visible_copy_in_the_new_views_has_no_dashes_or_exclamation_marks(): void
    {
        $root = dirname(__DIR__, 3).'/resources/views';
        $files = array_merge(glob($root.'/{cart,checkout,help,account,returns}/*.blade.php', GLOB_BRACE), glob($root.'/orders/{buyer,receipt,_payment-panel}.blade.php', GLOB_BRACE));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = preg_replace(['/\{\{--.*?--\}\}/s', '/<script.*?<\/script>/s', '/@php.*?@endphp/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], '', file_get_contents($file));
            $text = strip_tags(preg_replace('/<[^>]+>/', '>', $source));
            $this->assertStringNotContainsString('—', $source, basename($file).' has an em dash');
            $this->assertStringNotContainsString('–', $source, basename($file).' has an en dash');
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]!(\s|$)/', $text, basename($file).' has an exclamation mark');
        }
    }
}
