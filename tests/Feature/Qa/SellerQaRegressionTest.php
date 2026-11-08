<?php

namespace Tests\Feature\Qa;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\OperatorAuditEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Guests\GuestOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/**
 * Regressions found by the seller/operator/platform end-to-end QA pass (R-43, docs/R43_QA_seller.md). Each test names the
 * decision (D-15x) it protects.
 */
class SellerQaRegressionTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsOperatorWorld;
    use BuildsPayments;
    use BuildsSellerDesk;
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

    /** D-151 */
    public function test_cancelling_with_an_id_that_is_not_in_the_parcel_cancels_nothing_and_says_so(): void
    {
        [$seller, $product] = $this->shippingSeller();
        [, $group, $line] = $this->paidParcel($product);
        [, , $foreign] = $this->paidParcel($this->makeListing($this->makeSeller(), ['name' => 'Foreign Kettle '.uniqid(), 'price' => 100, 'stock' => 5]));

        $this->actingAs($seller)->from(route('seller.orders.show', $group))->post(route('seller.orders.cancel', $group), ['item_ids' => [$foreign->id], 'reason' => 'Out of stock after all'])
            ->assertSessionHasErrors('item_ids');
        $this->actingAs($seller)->from(route('seller.orders.show', $group))->post(route('seller.orders.cancel', $group), ['item_ids' => [$line->id, $foreign->id], 'reason' => 'Out of stock after all'])
            ->assertSessionHasErrors('item_ids');

        $this->assertSame(OrderStatus::Pending, $this->lineStatus($line), 'the one real line was not cancelled either: all or nothing');
        $this->assertSame(OrderStatus::Pending, $this->lineStatus($foreign), 'another seller\'s line is untouched');
    }

    /** D-152 */
    public function test_retrying_or_marking_done_a_refund_that_is_already_in_hand_is_refused_and_not_audited(): void
    {
        $w = $this->operatorWorld();
        $payment = Payment::query()->first();
        $make = fn (RefundStatus $status) => Refund::query()->create(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'amount_cents' => 500, 'currency' => 'ZAR', 'status' => $status, 'kind' => 'cancellation', 'provider_refund_id' => 'qa-'.uniqid()]);

        $done = $make(RefundStatus::Refunded);
        $inFlight = $make(RefundStatus::Submitted);

        foreach ([$done, $inFlight] as $refund) {
            $this->actingAs($w['operator'])->post(route('operator.refunds.retry', $refund))->assertRedirect(route('operator.payments.index'))->assertSessionHas('error');
            $this->assertSame(0, OperatorAuditEntry::query()->where('action', 'refund.retry')->count(), 'no "retried a refund" line for a call that was not made');
            $this->assertSame($refund->status, $refund->fresh()->status, 'nothing was sent');
        }

        $this->actingAs($w['operator'])->post(route('operator.refunds.mark-done', $done), ['note' => 'Second time, same refund.'])->assertSessionHasErrors('note');
        $this->assertSame(0, OperatorAuditEntry::query()->where('action', 'refund.mark_done')->count());

        $owed = $make(RefundStatus::Failed);
        $this->actingAs($w['operator'])->post(route('operator.refunds.mark-done', $owed), ['note' => 'Refunded by hand, ref PSK-77.'])->assertSessionHasNoErrors();
        $this->assertSame(RefundStatus::Refunded, $owed->fresh()->status);
        $this->assertSame(1, OperatorAuditEntry::query()->where('action', 'refund.mark_done')->count());
    }
}
