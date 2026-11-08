<?php

namespace Tests\Feature\Seller;

use App\Enums\StoreStatus;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\Team;
use App\Services\Stores\StoreOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** "Sell on Emall", opening a store, the checklist and its real state, review and its reasons, media, the InfoDot company link. */
class SellerOnboardingTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    public function test_the_start_page_is_public_and_says_what_selling_costs_how_returns_work_and_how_payouts_really_work(): void
    {
        config(['emall.commission.percent' => 5]);

        $page = $this->get(route('sell.start'))->assertOk();

        $page->assertSee('Sign in with InfoDot')->assertSee('Accounts on Emall come from InfoDot')
            ->assertSee('Dot.Emall takes 5% of the item price')
            ->assertSee('within 7 days of it arriving')->assertSee('within 10 days')
            ->assertSee('released 7 days after delivery')->assertSee('there are no automatic payouts yet')
            ->assertSee('claimed by you', false);
        $page->assertDontSee('Create account');
    }

    public function test_the_start_page_tells_the_truth_about_a_zero_commission(): void
    {
        $this->get(route('sell.start'))->assertSee('takes no commission');
    }

    public function test_a_signed_in_person_without_a_store_is_offered_the_first_step_and_an_owner_the_desk(): void
    {
        $this->actingAs($this->makeBuyer())->get(route('sell.start'))->assertOk()->assertSee('Open your store')->assertSee(route('seller.store.create'));
        $this->actingAs($this->makeSeller(['name' => 'Karoo Wares']))->get(route('sell.start'))->assertOk()->assertSee('Go to your seller desk')->assertSee('Karoo Wares');
    }

    public function test_a_visitor_who_signs_in_from_the_start_page_comes_back_to_the_first_step(): void
    {
        $this->get(route('sell.start'))->assertOk()->assertSessionHas('url.intended', route('seller.store.create'));
    }

    public function test_opening_a_store_takes_identity_contact_and_province_then_lands_on_the_checklist(): void
    {
        $user = $this->makeBuyer();

        $this->actingAs($user)->get(route('seller.store.create'))->assertOk()->assertSee('Open your store')->assertSee($user->email);
        $this->post(route('seller.store.store'), ['name' => 'Karoo Dust Co', 'description' => 'Hand made in the Karoo.', 'contact_email' => 'hello@karoodust.test', 'region' => 'Western Cape', 'city' => 'Ceres'])
            ->assertRedirect(route('seller.store.status'));

        $store = $user->fresh()->store;
        $this->assertSame(['Karoo Dust Co', 'Western Cape', 'Ceres', StoreStatus::Active], [$store->name, $store->region, $store->city, $store->status]);
    }

    public function test_the_form_refuses_what_is_wrong_in_words(): void
    {
        $user = $this->makeBuyer();

        $this->actingAs($user)->post(route('seller.store.store'), ['name' => '', 'contact_email' => 'not-an-email', 'region' => 'Narnia'])
            ->assertSessionHasErrors(['name', 'contact_email', 'region']);
        $this->assertNull($user->fresh()->store);
    }

    public function test_the_checklist_reads_its_state_from_the_store_and_a_missing_step_links_to_where_it_is_done(): void
    {
        $user = $this->makeBuyer();
        $this->actingAs($user)->post(route('seller.store.store'), ['name' => 'Bare Bones Trading']);

        $user = $user->fresh();
        $page = $this->actingAs($user)->get(route('seller.store.status'))->assertOk();
        $list = collect($page->viewData('checklist'))->keyBy('key');
        $this->assertTrue($list['identity']['done'] === false, 'no description yet');
        $this->assertFalse($list['contact_email']['done']);
        $this->assertFalse($list['shipping_methods']['done']);
        $this->assertTrue($list['contact_email']['required']);
        $this->assertFalse($list['logo']['required']);
        $this->assertStringContainsString('/sell/shipping', $list['shipping_methods']['href']);

        $store = $user->store;
        $store->update(['description' => 'Now described', 'contact_email' => 'x@y.test', 'region' => 'Gauteng', 'shipping_policy' => 'We post on weekdays.', 'return_policy' => 'Seven days.', 'logo_path' => 'store-media/1/logo-x.png']);
        ShippingMethod::factory()->create(['store_id' => $store->id]);
        $list = collect($this->actingAs($user->fresh())->get(route('seller.store.status'))->viewData('checklist'))->keyBy('key');
        foreach (['identity', 'logo', 'contact_email', 'region', 'shipping_policy', 'return_policy', 'shipping_methods'] as $key) {
            $this->assertTrue($list[$key]['done'], $key.' is done now');
        }
        $this->assertFalse($list['banner']['done']);
        $this->assertFalse($list['infodot_company']['done'], 'no company is linked');
    }

    public function test_with_review_switched_on_a_new_store_is_a_draft_that_must_be_completed_before_it_can_be_sent(): void
    {
        config(['emall.seller.requires_review' => true]);
        $user = $this->makeBuyer();
        $this->actingAs($user)->post(route('seller.store.store'), ['name' => 'Needs Review Co'])->assertRedirect(route('seller.store.status'));
        $user = $user->fresh();
        $store = $user->store;
        $this->assertSame([StoreStatus::Draft, false], [$store->status, $store->is_active]);

        $this->actingAs($user)->get(route('seller.store.status'))->assertSee('Draft')->assertSee('Send for review')->assertSee('Add a contact e-mail address');
        $this->post(route('seller.store.submit'))->assertSessionHasErrors(['contact_email', 'region', 'return_policy', 'shipping_policy', 'shipping_methods']);
        $this->assertSame(StoreStatus::Draft, $store->fresh()->status);

        $store->update(['contact_email' => 'a@b.test', 'region' => 'Gauteng', 'shipping_policy' => 'Posted weekly.', 'return_policy' => 'Seven days.']);
        ShippingMethod::factory()->create(['store_id' => $store->id]);
        $this->post(route('seller.store.submit'))->assertRedirect(route('seller.store.status'))->assertSessionHasNoErrors();
        $this->assertSame(StoreStatus::PendingReview, $store->fresh()->status);
        $this->get(route('seller.store.status'))->assertSee('Waiting for review')->assertDontSee('Send my store for review');

        // Sent twice: only a draft can be sent.
        $this->post(route('seller.store.submit'))->assertSessionHasErrors('status');
    }

    public function test_the_operators_reasons_are_shown_to_the_seller_on_the_status_page_and_in_the_strip(): void
    {
        config(['emall.seller.requires_review' => true]);
        $seller = $this->makeSeller();
        $store = $seller->store;
        $store->forceFill(['status' => StoreStatus::PendingReview, 'is_active' => false])->save();
        $operator = $this->operator();

        app(StoreOnboarding::class)->reject($store, $operator, 'The shipping policy does not say where you deliver.');
        $this->actingAs($seller->fresh())->get(route('seller.store.status'))->assertSee('The Emall team sent your store back')->assertSee('The shipping policy does not say where you deliver.');
        $this->get(route('seller.dashboard'))->assertSee('The Emall team sent it back: The shipping policy does not say where you deliver.', false);

        $store->refresh()->forceFill(['status' => StoreStatus::Active, 'is_active' => true])->save();
        app(StoreOnboarding::class)->suspend($store->fresh(), $operator, 'Counterfeit complaints are being looked into.');
        $this->actingAs($seller->fresh());
        $this->get(route('seller.store.status'))->assertSee('Suspended')->assertSee('Counterfeit complaints are being looked into.');
        $this->get(route('seller.products.index'))->assertSee('Your store is suspended')->assertSee('Counterfeit complaints are being looked into.');

        // A suspended store cannot be reopened by its owner.
        $this->patch(route('seller.store.update'), ['name' => $store->name, 'is_active' => '1']);
        $this->assertFalse($store->fresh()->is_active);
    }

    public function test_the_profile_saves_every_onboarding_field_with_claimed_numbers_and_a_vat_switch(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->patch(route('seller.store.update'), [
            'name' => 'Full Profile Trading', 'description' => 'About us', 'legal_name' => 'Full Profile (Pty) Ltd', 'contact_email' => 'info@full.test', 'contact_phone' => '+27 11 555 0100',
            'region' => 'KwaZulu-Natal', 'city' => 'Durban', 'return_policy' => 'Seven days, unused.', 'shipping_policy' => 'Ships Monday to Friday.',
            'vat_registered' => '1', 'vat_number' => '4123456789', 'registration_number' => '2019/123456/07', 'is_active' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('seller.store.edit'));

        $store = $seller->store()->first();
        $this->assertSame(['Full Profile (Pty) Ltd', 'KwaZulu-Natal', true, '4123456789', '2019/123456/07'], [$store->legal_name, $store->region, $store->vat_registered, $store->vat_number, $store->registration_number]);
        $page = $this->get(route('seller.store.edit'))->assertOk();
        $page->assertSee('(claimed)')->assertSee('4123456789')->assertSee('does not check them');

        // The form sends a 0 for an unticked switch, so unticking is heard.
        $this->patch(route('seller.store.update'), ['name' => 'Full Profile Trading', 'vat_registered' => '0', 'is_active' => '1']);
        $this->assertFalse($seller->store()->first()->vat_registered);
    }

    public function test_bad_profile_values_are_refused(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => 'x', 'contact_phone' => 'call me maybe', 'vat_number' => '<b>', 'region' => 'Mordor'])
            ->assertSessionHasErrors(['contact_phone', 'vat_number', 'region']);
    }

    public function test_a_logo_and_banner_are_stored_replaced_and_removed(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->patch(route('seller.store.update'), ['name' => $seller->store->name, 'is_active' => '1', 'logo' => UploadedFile::fake()->image('logo.png', 300, 300), 'banner' => UploadedFile::fake()->image('banner.jpg', 1200, 400)])->assertSessionHasNoErrors();
        $store = $seller->store()->first();
        Storage::disk('public')->assertExists($store->logo_path);
        Storage::disk('public')->assertExists($store->banner_path);
        $this->assertStringStartsWith('store-media/'.$store->id.'/logo-', $store->logo_path);
        $first = $store->logo_path;

        $this->patch(route('seller.store.update'), ['name' => $store->name, 'is_active' => '1', 'logo' => UploadedFile::fake()->image('new.png', 200, 200)])->assertSessionHasNoErrors();
        $store = $seller->store()->first();
        $this->assertNotSame($first, $store->logo_path);
        Storage::disk('public')->assertMissing($first);
        $this->get(route('seller.store.edit'))->assertSee('Your current logo');

        $this->patch(route('seller.store.update'), ['name' => $store->name, 'is_active' => '1', 'remove_logo' => '1', 'remove_banner' => '1']);
        $store = $seller->store()->first();
        $this->assertNull($store->logo_path);
        $this->assertNull($store->banner_path);
    }

    public function test_a_file_that_is_not_a_picture_or_is_too_small_or_big_is_refused(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->patch(route('seller.store.update'), ['name' => 'x', 'logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->patch(route('seller.store.update'), ['name' => 'x', 'banner' => UploadedFile::fake()->image('tiny.png', 20, 20)])->assertSessionHasErrors('banner');
        $this->patch(route('seller.store.update'), ['name' => 'x', 'logo' => UploadedFile::fake()->image('huge.png', 400, 400)->size(5000)])->assertSessionHasErrors('logo');
        $this->assertNull($seller->store()->first()->logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_closing_and_reopening_follow_an_explicit_switch_and_listings_follow(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Switch Follows Store Kettle']);

        $this->actingAs($seller)->patch(route('seller.store.update'), ['name' => $seller->store->name, 'is_active' => '0']);
        $this->assertFalse($seller->store()->first()->is_active);
        $this->get(route('seller.dashboard'))->assertSee('Your store is closed');

        $this->patch(route('seller.store.update'), ['name' => $seller->store->name]); // a field left out changes nothing
        $this->assertFalse($seller->store()->first()->is_active);

        $this->patch(route('seller.store.update'), ['name' => $seller->store->name, 'is_active' => '1']);
        $this->assertTrue($seller->store()->first()->is_active);
        $this->assertSame(1, Product::query()->browsable()->whereKey($product->id)->count());
    }

    public function test_an_infodot_company_can_be_linked_only_if_it_is_the_owners_own_company_from_infodot(): void
    {
        $seller = $this->makeSeller();
        $company = Team::forceCreate(['user_id' => $seller->id, 'name' => 'Karoo Holdings', 'personal_team' => false, 'infodot_team_uuid' => '7d9a2d6e-aaaa-bbbb-cccc-111111111111']);
        $seller->teams()->attach($company, ['role' => 'admin']);
        $plain = Team::forceCreate(['user_id' => $seller->id, 'name' => 'Not From InfoDot', 'personal_team' => false]);
        $seller->teams()->attach($plain, ['role' => 'admin']);
        $foreign = Team::forceCreate(['user_id' => $this->makeBuyer()->id, 'name' => 'Somebody Elses Company', 'personal_team' => false, 'infodot_team_uuid' => '7d9a2d6e-aaaa-bbbb-cccc-222222222222']);

        $page = $this->actingAs($seller)->get(route('seller.store.edit'))->assertOk()->assertSee('Not linked');
        $this->assertSame(['Karoo Holdings'], $page->viewData('companies')->pluck('name')->all(), 'only the owner\'s own InfoDot companies are offered');

        $this->post(route('seller.store.link-team'), ['team_id' => $foreign->id])->assertSessionHasErrors('team_id');
        $this->post(route('seller.store.link-team'), ['team_id' => $plain->id])->assertSessionHasErrors('team_id');
        $this->assertNull($seller->store()->first()->team_id);

        $this->post(route('seller.store.link-team'), ['team_id' => $company->id])->assertRedirect(route('seller.store.edit'));
        $this->assertSame($company->id, $seller->store()->first()->team_id);
        $this->get(route('seller.store.edit'))->assertSee('is your InfoDot company')->assertSee('has not marked this company as verified');
    }
}
