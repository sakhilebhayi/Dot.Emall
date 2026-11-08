<?php

namespace Tests\Feature\Operator;

use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Enums\StoreStatus;
use App\Models\ContentReport;
use App\Models\OperatorAuditEntry;
use App\Models\Refund;
use App\Models\Review;
use App\Notifications\OperatorDecisionNotification;
use App\Services\Community\ContentReports;
use App\Services\Operator\OperatorDecisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * Every decision about another party: refused without a reason, saved with an audit entry (who, what, about whom, why), and told to
 * the person it affects. Driven through the real pages, so the forms, the validation and the services are tested as one.
 */
class DecisionsTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    private function audit(string $action): ?OperatorAuditEntry
    {
        return OperatorAuditEntry::query()->where('action', $action)->latest('id')->first();
    }

    // ---- listings -----------------------------------------------------------------------------------------------------

    public function test_rejecting_a_listing_needs_a_reason_and_the_seller_reads_it(): void
    {
        $w = $this->operatorWorld();
        Notification::fake();

        $this->actingAs($w['operator'])->post(route('operator.products.reject', $w['pending']), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($w['operator'])->post(route('operator.products.reject', $w['pending']), ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->assertSame('pending_approval', $w['pending']->fresh()->status);
        $this->assertSame(0, OperatorAuditEntry::query()->count(), 'a refused decision leaves no entry');

        $this->actingAs($w['operator'])->post(route('operator.products.reject', $w['pending']), ['reason' => 'Add a clear photo of the kettle itself.'])->assertRedirect();

        $fresh = $w['pending']->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Add a clear photo of the kettle itself.', $fresh->rejected_reason);

        $entry = $this->audit('listing.reject');
        $this->assertSame($w['operator']->id, $entry->actor_id);
        $this->assertSame($w['operator']->name, $entry->actor_name);
        $this->assertSame('product', $entry->subject_type);
        $this->assertSame($w['pending']->id, $entry->subject_id);
        $this->assertSame('Add a clear photo of the kettle itself.', $entry->reason);
        $this->assertNotNull($entry->created_at);

        Notification::assertSentTo($w['seller'], OperatorDecisionNotification::class, fn ($n) => str_contains($n->toDatabase($w['seller'])['message'], 'Add a clear photo'));
    }

    public function test_approving_writes_an_entry_and_tells_the_seller(): void
    {
        $w = $this->operatorWorld();
        Notification::fake();

        $this->actingAs($w['operator'])->post(route('operator.products.approve', $w['pending']))->assertRedirect();

        $this->assertSame('approved', $w['pending']->fresh()->status);
        $this->assertSame($w['pending']->id, $this->audit('listing.approve')->subject_id);
        Notification::assertSentTo($w['seller'], OperatorDecisionNotification::class);
    }

    public function test_a_live_listing_can_be_taken_down_with_a_reason(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['operator'])->post(route('operator.products.reject', $w['live']), ['reason' => 'This is a replica of a branded product.'])->assertRedirect();

        $this->assertSame('rejected', $w['live']->fresh()->status);
        $this->assertSame('This is a replica of a branded product.', $this->audit('listing.takedown')->reason);
    }

    // ---- stores -------------------------------------------------------------------------------------------------------

    public function test_store_decisions_need_reasons_and_the_seller_sees_them(): void
    {
        $w = $this->operatorWorld();
        $store = $w['seller']->store;
        $store->forceFill(['status' => StoreStatus::PendingReview, 'is_active' => false, 'submitted_at' => now()])->save();
        Notification::fake();

        $this->actingAs($w['operator'])->post(route('operator.stores.reject', $store), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(StoreStatus::PendingReview, $store->fresh()->status);

        $this->actingAs($w['operator'])->post(route('operator.stores.reject', $store), ['reason' => 'Add your return policy and a delivery method.'])->assertRedirect();
        $this->assertSame(StoreStatus::Draft, $store->fresh()->status);
        $this->assertSame('Add your return policy and a delivery method.', $store->fresh()->status_reason);
        $this->assertSame('store.reject', $this->audit('store.reject')->action);

        $store->refresh()->forceFill(['status' => StoreStatus::PendingReview])->save();
        $this->actingAs($w['operator'])->post(route('operator.stores.approve', $store))->assertRedirect();
        $this->assertSame(StoreStatus::Active, $store->fresh()->status);
        $this->assertNotNull($this->audit('store.approve'));

        $this->actingAs($w['operator'])->post(route('operator.stores.suspend', $store), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(StoreStatus::Active, $store->fresh()->status);
        $this->actingAs($w['operator'])->post(route('operator.stores.suspend', $store), ['reason' => 'Three counterfeit complaints upheld.'])->assertRedirect();
        $this->assertSame(StoreStatus::Suspended, $store->fresh()->status);
        $this->assertFalse((bool) $store->fresh()->is_active);
        $this->assertSame('Three counterfeit complaints upheld.', $this->audit('store.suspend')->reason);

        Notification::assertSentTo($w['seller'], OperatorDecisionNotification::class, fn ($n) => str_contains($n->toDatabase($w['seller'])['message'], 'Three counterfeit complaints'));

        $this->actingAs($w['operator'])->post(route('operator.stores.reinstate', $store), ['reason' => 'Appeal accepted.'])->assertRedirect();
        $this->assertSame(StoreStatus::Active, $store->fresh()->status);
        $this->assertSame('Appeal accepted.', $this->audit('store.reinstate')->reason);
    }

    // ---- reviews and questions ----------------------------------------------------------------------------------------

    public function test_a_held_review_is_released_or_removed_with_a_reason(): void
    {
        $w = $this->operatorWorld();
        Notification::fake();

        $this->actingAs($w['operator'])->post(route('operator.moderation.reviews.remove', $w['held']), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(Review::HELD, $w['held']->fresh()->status);

        $this->actingAs($w['operator'])->post(route('operator.moderation.reviews.remove', $w['held']), ['reason' => 'It shares a phone number to take the sale elsewhere.'])->assertRedirect();
        $this->assertSame(Review::REMOVED, $w['held']->fresh()->status);
        $this->assertSame($w['operator']->id, $w['held']->fresh()->moderated_by);
        $this->assertSame('It shares a phone number to take the sale elsewhere.', $this->audit('review.remove')->reason);
        Notification::assertSentTo($w['reviewer'], OperatorDecisionNotification::class);

        $another = Review::factory()->create(['product_id' => $w['live']->id, 'user_id' => $this->makeBuyer()->id, 'rating' => 4]);
        $another->forceFill(['status' => Review::HELD])->save();
        $this->actingAs($w['operator'])->post(route('operator.moderation.reviews.release', $another))->assertRedirect();
        $this->assertSame(Review::PUBLISHED, $another->fresh()->status);
        $this->assertNotNull($this->audit('review.release'));
    }

    public function test_a_held_question_is_removed_with_a_reason_and_released_without_one(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['operator'])->post(route('operator.moderation.questions.remove', $w['question']), [])->assertSessionHasErrors('reason');
        $this->actingAs($w['operator'])->post(route('operator.moderation.questions.remove', $w['question']), ['reason' => 'It links to another site.'])->assertRedirect();
        $this->assertSame('hidden', $w['question']->fresh()->status);
        $this->assertSame('It links to another site.', $this->audit('question.remove')->reason);

        $other = \App\Models\ProductQuestion::factory()->held()->create(['product_id' => $w['live']->id, 'user_id' => $this->makeBuyer()->id]);
        $this->actingAs($w['operator'])->post(route('operator.moderation.questions.release', $other))->assertRedirect();
        $this->assertSame('published', $other->fresh()->status);
    }

    // ---- reports --------------------------------------------------------------------------------------------------------

    public function test_settling_reports_needs_an_outcome_note_either_way_and_is_audited(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['operator'])->post(route('operator.reports.resolve', ['type' => 'review', 'id' => $w['reported']->id]), ['action' => 'dismiss', 'note' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('open', $w['report']->fresh()->status);

        $this->actingAs($w['operator'])->post(route('operator.reports.resolve', ['type' => 'review', 'id' => $w['reported']->id]), ['action' => 'remove', 'note' => 'Written by the seller, not a buyer.'])->assertRedirect(route('operator.reports'));

        $this->assertSame(Review::REMOVED, $w['reported']->fresh()->status);
        $report = $w['report']->fresh();
        $this->assertSame('actioned', $report->status);
        $this->assertSame('Written by the seller, not a buyer.', $report->resolution);
        $this->assertSame($w['operator']->id, $report->handled_by);
        $this->assertNotNull($this->audit('review.remove'));
    }

    public function test_a_dismissed_report_leaves_the_item_up_and_keeps_the_note(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['operator'])->post(route('operator.reports.resolve', ['type' => 'review', 'id' => $w['reported']->id]), ['action' => 'dismiss', 'note' => 'A genuine review; the shop is good.'])->assertRedirect();

        $this->assertSame(Review::PUBLISHED, $w['reported']->fresh()->status);
        $this->assertSame('dismissed', $w['report']->fresh()->status);
        $this->assertSame('A genuine review; the shop is good.', $this->audit('report.dismiss')->reason);
    }

    public function test_listings_and_stores_can_be_reported_and_acted_on(): void
    {
        $w = $this->operatorWorld();
        $reports = app(ContentReports::class);

        $reports->report($w['buyer'], 'product', $w['live']->id, 'fake', 'Looks like a knock-off');
        $reports->report($w['reviewer'], 'store', $w['seller']->store->id, 'other', 'Never delivered');

        $this->assertSame(3, ContentReport::query()->where('status', 'open')->count(), 'the review report from the world plus these two');
        $this->actingAs($w['operator'])->get(route('operator.reports', ['type' => 'product']))->assertOk()->assertSee($w['live']->name);

        $this->actingAs($w['operator'])->post(route('operator.reports.resolve', ['type' => 'product', 'id' => $w['live']->id]), ['action' => 'remove', 'note' => 'Counterfeit confirmed with the brand.'])->assertRedirect();
        $this->assertSame('rejected', $w['live']->fresh()->status);
        $this->assertSame('Counterfeit confirmed with the brand.', $w['live']->fresh()->rejected_reason);

        $this->actingAs($w['operator'])->post(route('operator.reports.resolve', ['type' => 'store', 'id' => $w['seller']->store->id]), ['action' => 'remove', 'note' => 'Orders were not delivered, see the reports.'])->assertRedirect();
        $this->assertSame(StoreStatus::Suspended, $w['seller']->store->fresh()->status);
        $this->assertSame(1, ContentReport::query()->where('status', 'open')->count(), 'only the review report is left');
    }

    public function test_a_seller_cannot_report_their_own_listing_or_store(): void
    {
        $w = $this->operatorWorld();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ContentReports::class)->report($w['seller'], 'store', $w['seller']->store->id, 'other');
    }

    public function test_repeat_offenders_are_people_with_two_items_taken_down_in_ninety_days(): void
    {
        $w = $this->operatorWorld();
        $author = $this->makeBuyer();
        $decisions = app(OperatorDecisions::class);

        foreach (range(1, 2) as $i) {
            $review = Review::factory()->create(['product_id' => $w['live']->id, 'user_id' => $author->id === $w['buyer']->id ? $this->makeBuyer()->id : $author->id, 'rating' => 5, 'body' => "Spam {$i}"]);
            \App\Models\Product::factory()->create(['store_id' => $w['seller']->store->id]); // another listing so the unique review per product holds
            $other = \App\Models\Product::factory()->create(['store_id' => $w['seller']->store->id]);
            $review->forceFill(['product_id' => $other->id])->save();
            ContentReport::query()->create(['reportable_type' => 'review', 'reportable_id' => $review->id, 'reporter_id' => $w['reporter']->id, 'reason' => 'spam']);
            $decisions->resolveReport($w['operator'], 'review', $review->id, 'remove', 'Spam, see the report.');
        }

        $this->actingAs($w['operator'])->get(route('operator.reports', ['view' => 'repeat']))->assertOk()->assertSee($author->name)->assertSee('Repeat offenders');
        $this->assertSame(1, app(\App\Services\Operator\OperatorReports::class)->repeatOffenders()->count());
    }

    // ---- returns --------------------------------------------------------------------------------------------------------

    public function test_settling_a_return_needs_a_note_and_the_buyer_sees_the_decision(): void
    {
        $w = $this->operatorWorld();
        Notification::fake();

        $this->actingAs($w['operator'])->post(route('operator.returns.settle', $w['return']), ['decision' => 'approve', 'note' => ''])->assertSessionHasErrors();
        $this->assertSame(ReturnStatus::Escalated, $w['return']->fresh()->status);

        $this->actingAs($w['operator'])->post(route('operator.returns.settle', $w['return']), ['decision' => 'approve', 'note' => 'The video shows the fault.'])->assertRedirect(route('operator.returns.index'));

        $return = $w['return']->fresh();
        $this->assertSame(ReturnStatus::Approved, $return->status);
        $this->assertSame('The video shows the fault.', $return->resolution_note);
        $this->assertSame($w['operator']->id, $return->resolved_by);
        $this->assertSame('The video shows the fault.', $this->audit('return.approve')->reason);

        $this->actingAs($w['buyer'])->get(route('orders.show', $w['order']))->assertOk();
    }

    public function test_approve_and_refund_creates_the_refund_through_the_return_service(): void
    {
        $w = $this->operatorWorld();
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 9101, 'status' => 'pending']], 200)]);

        $this->actingAs($w['operator'])->post(route('operator.returns.settle', $w['return']), ['decision' => 'approve_refund', 'note' => 'Goods are back with the courier.'])->assertRedirect();

        $this->assertSame(ReturnStatus::Refunded, $w['return']->fresh()->status);
        $refund = Refund::query()->where('order_id', $w['order']->id)->where('kind', 'return')->first();
        $this->assertNotNull($refund, 'a return refund was created');
        $this->assertSame($w['return']->fresh()->refund_cents, $refund->amount_cents);
        $this->assertNotNull($this->audit('return.approve_refund'));
    }

    public function test_upholding_the_decline_closes_the_return_with_no_refund(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['operator'])->post(route('operator.returns.settle', $w['return']), ['decision' => 'uphold_decline', 'note' => 'The photos show water damage.'])->assertRedirect();

        $this->assertSame(ReturnStatus::Closed, $w['return']->fresh()->status);
        $this->assertSame(0, Refund::query()->where('kind', 'return')->count());
        $this->assertNotNull($this->audit('return.uphold_decline'));
    }

    // ---- money ----------------------------------------------------------------------------------------------------------

    public function test_payment_review_and_refund_actions_are_audited(): void
    {
        $w = $this->operatorWorld();
        $payment = \App\Models\Payment::query()->first();
        $refund = Refund::query()->create(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'amount_cents' => 500, 'currency' => 'ZAR', 'status' => RefundStatus::Failed, 'kind' => 'cancellation']);

        $this->actingAs($w['operator'])->post(route('operator.payments.review', $payment), ['note' => 'Refunded in the Paystack dashboard.'])->assertRedirect(route('operator.payments.index'));
        $this->assertSame('Refunded in the Paystack dashboard.', $this->audit('payment.review')->reason);

        $this->actingAs($w['operator'])->post(route('operator.refunds.mark-done', $refund), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($w['operator'])->post(route('operator.refunds.mark-done', $refund), ['note' => 'Dashboard ref PSK-1234.'])->assertRedirect(route('operator.payments.index'));
        $this->assertSame('Dashboard ref PSK-1234.', $this->audit('refund.mark_done')->reason);
        $this->assertSame(RefundStatus::Refunded, $refund->fresh()->status);
    }

    // ---- the trail itself -----------------------------------------------------------------------------------------------

    public function test_the_audit_log_is_append_only(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator'])->post(route('operator.products.approve', $w['pending']));
        $entry = $this->audit('listing.approve');

        try {
            $entry->update(['reason' => 'rewritten']);
            $this->fail('an entry must not be updated');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_the_audit_page_lists_decisions_with_who_what_why(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator'])->post(route('operator.products.reject', $w['pending']), ['reason' => 'Photographs are missing from this listing.']);

        $this->get(route('operator.audit'))->assertOk()->assertSee($w['operator']->name)->assertSee('Rejected a listing')->assertSee('Pendingware kettle')->assertSee('Photographs are missing from this listing.');
        $this->get(route('operator.audit', ['action' => 'listing.*']))->assertOk()->assertSee('Rejected a listing');
        $this->get(route('operator.audit', ['action' => 'store.*']))->assertOk()->assertDontSee('Pendingware kettle');
    }
}
