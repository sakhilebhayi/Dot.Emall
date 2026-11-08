<?php

namespace Tests\Feature\Qa;

use App\Models\Order;
use App\Models\User;
use App\Services\Guests\GuestOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions found by the seller/operator/platform end-to-end QA pass (R-43, docs/R43_QA_seller.md). Each test names the
 * decision (D-15x) it protects.
 */
class SellerQaRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** D-150 */
    public function test_a_typed_new_email_address_is_unverified_and_cannot_claim_a_strangers_guest_orders(): void
    {
        $victim = 'guest.victim@example.test';
        $order = Order::factory()->create(['user_id' => null, 'guest_email' => $victim]);

        $attacker = User::factory()->withPersonalTeam()->create(['email' => 'attacker@example.test']);
        $this->assertNotNull($attacker->email_verified_at);

        $this->actingAs($attacker)
            ->put('/user/profile-information', ['name' => $attacker->name, 'email' => $victim])
            ->assertSessionHasNoErrors();

        $attacker->refresh();
        $this->assertSame($victim, $attacker->email);
        $this->assertNull($attacker->email_verified_at, 'a typed address is not a verified one');

        $this->assertSame(0, app(GuestOrders::class)->attachTo($attacker));
        $this->assertNull($order->fresh()->user_id, 'the guest order did not move to the attacker');
    }

    /** D-150 */
    public function test_changing_only_the_name_keeps_the_address_verified(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)->put('/user/profile-information', ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertEquals($verifiedAt?->timestamp, $user->fresh()->email_verified_at->timestamp);
        $this->assertSame('New Name', $user->fresh()->name);
    }

    /** D-150: the same address in another letter case is not a change. */
    public function test_a_case_only_change_of_the_address_stays_verified(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'mixed@example.test']);

        $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => 'Mixed@Example.test'])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
