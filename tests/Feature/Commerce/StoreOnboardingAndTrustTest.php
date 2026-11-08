<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Enums\StoreStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Returns\ReturnService;
use App\Services\Stores\StoreOnboarding;
use App\Services\Stores\StoreTrust;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class StoreOnboardingAndTrustTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function onboarding(): StoreOnboarding
    {
        return app(StoreOnboarding::class);
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    private function complete(Store $store): Store
    {
        $this->onboarding()->update($store, [
            'name' => $store->name, 'contact_email' => 'shop@example.test', 'region' => 'Gauteng',
            'return_policy' => 'Seven days, unused, in the box.', 'shipping_policy' => 'We ship within two working days.',
        ]);
        $this->withDelivery($store->owner->fresh());

        return $store->refresh();
    }

    // ---- lifecycle ---------------------------------------------------------------------------------------------------

    public function test_a_new_store_is_live_as_before_unless_review_is_switched_on(): void
    {
        $store = $this->onboarding()->create($this->makeBuyer(), ['name' => 'Sipho Hardware']);
        $this->assertSame([StoreStatus::Active, true], [$store->status, $store->is_active]);

        config(['emall.seller.requires_review' => true]);
        $draft = $this->onboarding()->create($this->makeBuyer(), ['name' => 'Review First']);
        $this->assertSame([StoreStatus::Draft, false], [$draft->status, $draft->is_active]);
        $this->assertStringStartsWith('review-first-', $draft->slug);
    }

    public function test_the_existing_become_a_seller_form_still_works_and_follows_the_setting(): void
    {
        $this->actingAs($u = $this->makeBuyer())->post(route('seller.store.store'), ['name' => 'Plain Store', 'description' => 'x'])->assertRedirect(route('seller.products.create'));
        $this->assertSame(StoreStatus::Active, $u->fresh()->store->status);

        config(['emall.seller.requires_review' => true]);
        $this->actingAs($v = $this->makeBuyer())->post(route('seller.store.store'), ['name' => 'Drafted Store'])->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'draft'));
        $this->assertSame(StoreStatus::Draft, $v->fresh()->store->status);
    }

    public function test_store_fields_are_validated(): void
    {
        foreach ([['contact_email' => 'nope'], ['region' => 'Atlantis'], ['contact_phone' => 'call'], ['vat_number' => '!!'], ['registration_number' => '<script>'], ['name' => '']] as $bad) {
            try {
                $this->onboarding()->create($this->makeBuyer(), array_merge(['name' => 'Valid Name'], $bad));
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_draft_cannot_be_submitted_until_the_basics_are_filled_in(): void
    {
        config(['emall.seller.requires_review' => true]);
        $store = $this->onboarding()->create($this->makeBuyer(), ['name' => 'Half Done']);

        try {
            $this->onboarding()->submit($store);
            $this->fail('incomplete');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['contact_email', 'region', 'return_policy', 'shipping_policy', 'shipping_methods'], array_keys($e->errors()));
        }

        $store = $this->complete($store);
        $submitted = $this->onboarding()->submit($store);

        $this->assertSame(StoreStatus::PendingReview, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertFalse($submitted->is_active);
        $this->assertCount(1, $this->onboarding()->reviewQueue());

        $this->expectException(ValidationException::class);
        $this->onboarding()->submit($submitted);
    }

    public function test_an_operator_approves_and_the_store_goes_live_with_a_record_of_who(): void
    {
        config(['emall.seller.requires_review' => true]);
        $store = $this->complete($this->onboarding()->create($this->makeBuyer(), ['name' => 'Ready Store']));
        $this->onboarding()->submit($store);
        $operator = $this->operator();
        $product = $this->makeListing($store->owner->fresh(), []);

        $this->assertNull(Product::browsable()->find($product->id), 'a pending store is not browsable');

        $approved = $this->onboarding()->approve($store->fresh(), $operator);

        $this->assertSame([StoreStatus::Active, true, $operator->id], [$approved->status, $approved->is_active, $approved->reviewed_by]);
        $this->assertNotNull(Product::browsable()->find($product->id));
    }

    public function test_a_rejection_goes_back_to_draft_with_the_reason_and_can_be_resubmitted(): void
    {
        config(['emall.seller.requires_review' => true]);
        $store = $this->complete($this->onboarding()->create($this->makeBuyer(), ['name' => 'Needs Work']));
        $this->onboarding()->submit($store);

        foreach (['', '   ', 'no'] as $empty) {
            try {
                $this->onboarding()->reject($store->fresh(), $this->operator(), $empty);
                $this->fail('a reason is mandatory');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $rejected = $this->onboarding()->reject($store->fresh(), $this->operator(), 'The return policy is blank placeholder text.');

        $this->assertSame([StoreStatus::Draft, false, 'The return policy is blank placeholder text.'], [$rejected->status, $rejected->is_active, $rejected->status_reason]);

        $again = $this->onboarding()->submit($rejected);
        $this->assertNull($again->status_reason, 'the old reason is cleared when it is submitted again');
    }

    public function test_only_operators_review_and_a_suspension_needs_a_reason_and_hides_the_listings(): void
    {
        $store = $this->makeSeller()->store;
        $product = $this->makeListing($store->owner, []);

        foreach (['approve', 'reinstate'] as $method) {
            try {
                $this->onboarding()->{$method}($store, $this->makeBuyer());
                $this->fail('operators only');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->onboarding()->suspend($store, $this->makeBuyer(), 'a good reason');
            $this->fail('operators only');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectedValidation(fn () => $this->onboarding()->suspend($store, $this->operator(), ''));

        $this->onboarding()->suspend($store, $this->operator(), 'Counterfeit reports under investigation.');

        $this->assertSame(StoreStatus::Suspended, $store->fresh()->status);
        $this->assertNull(Product::browsable()->find($product->id));

        // The owner cannot reopen a suspended store from the settings form.
        $this->actingAs($store->owner)->patch(route('seller.store.update'), ['name' => $store->name, 'is_active' => 1]);
        $this->assertFalse($store->fresh()->is_active);

        $this->onboarding()->reinstate($store->fresh(), $this->operator());
        $this->assertTrue($store->fresh()->is_active);
        $this->assertNotNull(Product::browsable()->find($product->id));
    }

    public function test_the_settings_form_keeps_working_and_does_not_wipe_the_new_fields(): void
    {
        $store = $this->makeSeller()->store;
        $this->onboarding()->update($store, ['name' => $store->name, 'vat_registered' => true, 'vat_number' => '4012345678', 'return_policy' => 'Keep it.']);

        $this->actingAs($store->owner)->patch(route('seller.store.update'), ['name' => 'Renamed Store', 'description' => 'New words', 'is_active' => 1])->assertRedirect(route('seller.store.edit'));

        $fresh = $store->fresh();
        $this->assertSame(['Renamed Store', true, '4012345678', 'Keep it.', true], [$fresh->name, $fresh->vat_registered, $fresh->vat_number, $fresh->return_policy, $fresh->is_active]);

        $this->actingAs($store->owner)->patch(route('seller.store.update'), ['name' => '', 'is_active' => 1])->assertSessionHasErrors('name');
    }

    public function test_a_store_links_only_to_the_owners_own_infodot_company(): void
    {
        $owner = $this->makeSeller();
        $store = $owner->store;
        $company = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => '11111111-1111-4111-8111-111111111111']);
        $local = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Local only', 'personal_team' => false]);
        $foreign = Team::forceCreate(['user_id' => $this->makeBuyer()->id, 'name' => 'Not mine', 'personal_team' => false, 'infodot_team_uuid' => '22222222-2222-4222-8222-222222222222']);

        $this->assertSame($company->id, $this->onboarding()->linkTeam($store, $company)->team_id);

        foreach ([$local, $foreign] as $bad) {
            try {
                $this->onboarding()->linkTeam($store, $bad);
                $this->fail('refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the trust ladder --------------------------------------------------------------------------------------------

    private function rung(array $trust, string $key): ?array
    {
        return collect($trust['ladder'])->firstWhere('key', $key);
    }

    public function test_a_new_store_has_an_honest_empty_ladder_and_no_verified_badge(): void
    {
        $seller = $this->makeSeller();

        $trust = StoreTrust::for($seller->store);

        $this->assertFalse($trust['verified']);
        $this->assertSame('no', $this->rung($trust, 'infodot_sign_in')['state']);
        $this->assertSame('no', $this->rung($trust, 'infodot_company')['state']);
        $this->assertNull($this->rung($trust, 'infodot_verified'));
        foreach (['fulfilment_rate', 'median_hours_to_ship', 'return_rate'] as $metric) {
            $this->assertNull($trust['metrics'][$metric]['value'], $metric);
            $this->assertStringContainsString('Not enough orders yet', $trust['metrics'][$metric]['note']);
        }
        $this->assertSame(0, $trust['metrics']['review_count']['value']);
        $this->assertNull($trust['metrics']['review_average']['value']);
        $this->assertSame(0, $trust['months_on_emall']);
    }

    public function test_claims_are_labelled_claimed_and_never_checked(): void
    {
        $seller = $this->makeSeller(['registration_number' => '2019/123456/07', 'vat_registered' => true, 'vat_number' => '4012345678']);

        $trust = StoreTrust::for($seller->store);

        $this->assertSame(['claimed', 'Seller'], [$this->rung($trust, 'registration_number')['state'], $this->rung($trust, 'registration_number')['source']]);
        $this->assertStringContainsString('claimed by the seller, not checked', $this->rung($trust, 'registration_number')['detail']);
        $this->assertStringContainsString('claimed by the seller, not checked', $this->rung($trust, 'vat_number')['detail']);
        $this->assertFalse($trust['verified'], 'claims never make a verified seller');
    }

    public function test_the_infodot_rungs_come_from_real_sign_in_and_company_data(): void
    {
        $seller = $this->makeSeller();
        $company = Team::forceCreate(['user_id' => $seller->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => '11111111-1111-4111-8111-111111111111']);
        $seller->store->update(['team_id' => $company->id]);
        $seller->forceFill(['infodot_signed_in_at' => now()])->save();

        $trust = StoreTrust::for($seller->store->fresh());

        $this->assertSame('yes', $this->rung($trust, 'infodot_sign_in')['state']);
        $this->assertSame(['yes', 'Acme Ltd'], [$this->rung($trust, 'infodot_company')['state'], $this->rung($trust, 'infodot_company')['detail']]);
        $this->assertFalse($trust['verified'], 'a linked company is not a verified one');

        $company->forceFill(['infodot_verified_at' => now()])->save();
        $trust = StoreTrust::for($seller->store->fresh());
        $this->assertTrue($trust['verified']);
        $this->assertSame('yes', $this->rung($trust, 'infodot_verified')['state']);
    }

    public function test_signing_in_through_infodot_records_the_marker_and_only_infodot_can_verify_a_company(): void
    {
        config(['ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'emall', 'secret' => 's', 'timeout' => 2, 'provision' => true]]);
        $uuid = '33333333-3333-4333-8333-333333333333';
        $signIn = function (array $team) use ($uuid) {
            Http::swap(new Factory);
            Http::fake(['infodot.test/*' => Http::response([
                'user' => ['id' => 1, 'name' => 'Ann', 'email' => 'ann@acme.test', 'email_verified' => true],
                'teams' => [array_merge(['id' => 1, 'uuid' => $uuid, 'name' => 'Acme', 'personal' => false, 'role' => 'owner'], $team)],
                'current_team_uuid' => $uuid,
            ])]);
            auth()->logout();
            $this->post(route('ecosystem.auth'), ['token' => 'x'], ['Origin' => 'https://infodot.test'])->assertRedirect();
        };

        $signIn([]);
        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', $uuid)->firstOrFail();
        $this->assertNotNull($user->infodot_signed_in_at);
        $this->assertNull($team->infodot_verified_at, 'today\'s InfoDot payload says nothing about verification');

        $signIn(['verified' => true]);
        $this->assertNotNull($team->fresh()->infodot_verified_at);

        $signIn(['verified' => false]);
        $this->assertNull($team->fresh()->infodot_verified_at, 'InfoDot withdrawing it withdraws the rung');
    }

    public function test_infodot_sign_in_attaches_guest_orders_for_the_verified_address(): void
    {
        config(['ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'emall', 'secret' => 's', 'timeout' => 2, 'provision' => true]]);
        $seller = $this->makeSeller();
        $variant = $this->makeListing($seller)->defaultVariant()->first();
        $order = app(CheckoutService::class)->placeAsGuest('Ann@Acme.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => 'x']);

        Http::fake(['infodot.test/*' => Http::response(['user' => ['id' => 1, 'name' => 'Ann', 'email' => 'ann@acme.test', 'email_verified' => true], 'teams' => []])]);
        $this->post(route('ecosystem.auth'), ['token' => 'x'], ['Origin' => 'https://infodot.test'])->assertRedirect();

        $this->assertSame(User::where('email', 'ann@acme.test')->value('id'), $order->fresh()->user_id);
    }

    /** A paid, shipped parcel from the store, `$hoursToShip` after payment, against a handling time of 2 working days. */
    private function parcel(Store $store, ?int $hoursToShip, int $createdDaysAgo = 20, string $status = 'shipped'): void
    {
        $paidAt = now()->subDays($createdDaysAgo);
        $order = Order::factory()->create(['user_id' => User::factory(), 'requires_payment' => true, 'payment_status' => 'paid', 'paid_at' => $paidAt, 'created_at' => $paidAt, 'status' => $status === 'cancelled' ? 'cancelled' : 'pending']);
        OrderGroup::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'seller_id' => $store->owner_id, 'store_name' => $store->name, 'method_type' => 'flat',
            'handling_days' => 2, 'status' => $status, 'shipped_at' => $hoursToShip === null ? null : $paidAt->copy()->addHours($hoursToShip),
        ]);
    }

    public function test_fulfilment_rate_and_median_hours_are_shown_only_with_enough_orders_and_come_from_shipped_times(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        $store = $this->makeSeller()->store;

        foreach ([10, 20, 30, 40] as $hours) {
            $this->parcel($store, $hours);
        }
        $trust = StoreTrust::for($store->fresh());
        $this->assertNull($trust['metrics']['fulfilment_rate']['value'], 'four orders is below the minimum sample');
        $this->assertNull($trust['metrics']['median_hours_to_ship']['value']);

        $this->parcel($store, 100); // paid on a Tuesday: two working days is Thursday night, this left on Saturday: late
        $this->parcel($store, null); // 20 days old and never shipped: due, and missed
        $trust = StoreTrust::for($store->fresh());

        $this->assertSame(6, $trust['metrics']['fulfilment_rate']['sample']);
        $this->assertEqualsWithDelta(66.7, (float) $trust['metrics']['fulfilment_rate']['value'], 0.05);
        $this->assertSame(5, $trust['metrics']['median_hours_to_ship']['sample']);
        $this->assertEqualsWithDelta(30.0, (float) $trust['metrics']['median_hours_to_ship']['value'], 0.05);
        $this->assertStringContainsString('Based on 6 orders', $trust['metrics']['fulfilment_rate']['note']);
    }

    public function test_an_unshipped_parcel_that_is_not_due_yet_and_cancelled_parcels_count_for_nothing(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        $store = $this->makeSeller()->store;

        foreach (range(1, 5) as $n) {
            $this->parcel($store, 5);
        }
        $this->parcel($store, null, createdDaysAgo: 0);              // placed today, handling time not up
        $this->parcel($store, null, createdDaysAgo: 30, status: 'cancelled');

        $metric = StoreTrust::for($store->fresh())['metrics']['fulfilment_rate'];

        $this->assertSame([5, 100.0], [$metric['sample'], (float) $metric['value']]);
    }

    public function test_return_rate_review_figures_and_months_on_emall(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        $seller = $this->makeSeller();
        $store = $seller->store;
        $store->forceFill(['created_at' => now()->subMonths(14)])->save();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, array_map(fn () => [$this->makeListing($seller, ['rating_count' => 0]), 1], range(1, 5)));
        $this->deliver($order);
        app(ReturnService::class)->request($order->items()->first()->fresh(), $buyer, 'faulty');
        Product::query()->where('store_id', $store->id)->limit(2)->get()->each(function ($p, $i) {
            DB::table('products')->where('id', $p->id)->update(['rating_count' => $i === 0 ? 3 : 1, 'rating_average' => $i === 0 ? 4.0 : 2.0]);
        });

        $trust = StoreTrust::for($store->fresh());

        $this->assertSame([5, 20.0], [$trust['metrics']['return_rate']['sample'], (float) $trust['metrics']['return_rate']['value']]);
        $this->assertSame(4, $trust['metrics']['review_count']['value']);
        $this->assertEqualsWithDelta(3.5, $trust['metrics']['review_average']['value'], 0.001);
        $this->assertSame(14, $trust['months_on_emall']);
    }

    public function test_the_trust_ladder_costs_a_fixed_number_of_queries(): void
    {
        $store = $this->makeSeller()->store;
        foreach (range(1, 8) as $n) {
            $this->parcel($store, 5);
        }

        DB::enableQueryLog();
        StoreTrust::for($store->fresh());
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(1, 30) as $n) {
            $this->parcel($store, 5);
        }
        DB::flushQueryLog();
        StoreTrust::for($store->fresh());
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'the number of queries does not grow with the number of orders');
        $this->assertLessThanOrEqual(10, $large);
        $this->assertSame(OrderStatus::Pending->value, Order::query()->first()->status->value);
    }

    private function expectedValidation(callable $do): void
    {
        try {
            $do();
            $this->fail('expected a validation error');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
