<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class OperatorPaymentsTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    public function test_only_operators_may_open_the_payments_desk(): void
    {
        $this->get(route('operator.payments.index'))->assertRedirect(route('login'));
        $this->actingAs($this->makeBuyer())->get(route('operator.payments.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_platform_operator' => true]))->get(route('operator.payments.index'))->assertOk();
    }

    public function test_it_lists_unmatched_events_and_lets_an_operator_close_a_review_with_a_note(): void
    {
        $seller = $this->makeSeller();
        $order = $this->enablePaystackAndPlace($this->makeBuyer(), [[$this->makeListing($seller, ['price' => 80]), 1]]);
        $payment = $this->startAttempt($order);
        $this->postWebhook($this->chargeSuccessPayload($payment, ['amount' => 1]))->assertOk();
        $payment->refresh();
        $this->assertSame(PaymentAttemptStatus::NeedsReview, $payment->status);
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $page = $this->actingAs($operator)->get(route('operator.payments.index'));
        $page->assertSee($order->reference())->assertSee($payment->reference)->assertSee('Needs review');
        $this->assertTrue(PaymentEvent::where('outcome', 'unmatched')->exists());

        $this->actingAs($operator)->post(route('operator.payments.review', $payment), ['note' => 'Customer paid R0.01 by mistake, refunded in dashboard'])
            ->assertRedirect(route('operator.payments.index'));

        $payment->refresh();
        $this->assertNotNull($payment->reviewed_at);
        $this->assertSame($operator->id, $payment->reviewed_by);
        $this->assertSame('Customer paid R0.01 by mistake, refunded in dashboard', $payment->review_note);
        $this->actingAs($operator)->get(route('operator.payments.index'))->assertDontSee('Customer paid R0.01 by mistake, refunded in dashboard');
    }

    public function test_reviewing_requires_a_note_and_is_operator_only(): void
    {
        $seller = $this->makeSeller();
        $order = $this->enablePaystackAndPlace($this->makeBuyer(), [[$this->makeListing($seller), 1]]);
        $payment = $this->startAttempt($order);
        $payment->forceFill(['status' => PaymentAttemptStatus::NeedsReview])->save();

        $this->actingAs($this->makeBuyer())->post(route('operator.payments.review', $payment), ['note' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_platform_operator' => true]))
            ->post(route('operator.payments.review', $payment), ['note' => ''])->assertSessionHasErrors('note');
        $this->assertNull($payment->fresh()->reviewed_at);
    }

    public function test_the_page_warns_when_the_gateway_is_in_test_mode_or_misconfigured(): void
    {
        $operator = User::factory()->create(['is_platform_operator' => true]);

        $this->enablePaystack();
        $this->actingAs($operator)->get(route('operator.payments.index'))->assertSee('TEST MODE');

        $this->enablePaystack('sk_live_refused_0000');
        $this->actingAs($operator)->get(route('operator.payments.index'))->assertSee('refused');

        config(['paystack.secret_key' => '']);
        $this->actingAs($operator)->get(route('operator.payments.index'))->assertSee('not configured');
    }
}
