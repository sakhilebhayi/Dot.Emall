<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\ContentReport;
use App\Models\LedgerEntry;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Services\Community\ProductQuestions;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** The ledger page and its file, questions and reviews, the dashboard's to-do list and its real numbers. */
class SellerLedgerFeedbackDashboardTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- ledger ------------------------------------------------------------------------------------------------------------

    public function test_the_ledger_shows_held_then_released_with_the_fee_breakdown_and_the_payout_policy_in_plain_words(): void
    {
        config(['emall.commission.percent' => 5]);
        [$seller, $product] = $this->shippingSeller([], ['price' => 200]);
        [$order, $group] = $this->paidParcel($product);

        $page = $this->actingAs($seller)->get(route('seller.ledger'))->assertOk();
        $page->assertSee($order->reference())->assertSee('R260.00')->assertSee('-R10.00')->assertSee('5%')->assertSee('R250.00')->assertSee('Starts when delivered')
            ->assertSee('Emall does not pay out automatically yet')->assertSee('Nothing on this page means money has reached your bank account')->assertSee('does not show a per-entry check result');
        $this->assertSame(['held' => 25000, 'released' => 0, 'commission' => 1000], app(LedgerService::class)->balance($seller)['ZAR']);

        $this->shipParcel($group);
        $this->advanceAll($order->fresh(), OrderStatus::Delivered);
        $entry = LedgerEntry::query()->where('order_group_id', $group->id)->first();
        $this->assertNotNull($entry->release_at);
        $this->get(route('seller.ledger'))->assertSee('From '.$entry->release_at->format('j M Y'));

        Carbon::setTestNow(now()->addDays(8));
        app(LedgerService::class)->releaseDue();
        $this->get(route('seller.ledger'))->assertSee('Released to you, after Emall\'s fee', false);
        $this->assertSame(['held' => 0, 'released' => 25000, 'commission' => 1000], app(LedgerService::class)->balance($seller)['ZAR']);
        $this->get(route('seller.ledger', ['status' => 'released']))->assertSee($order->reference());
        $this->assertSame(0, $this->get(route('seller.ledger', ['status' => 'held']))->viewData('entries')->total());
    }

    public function test_a_cancellation_shows_as_a_reversal_that_nets_the_sale_off(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['price' => 200]);
        [$order, $group, $line] = $this->paidParcel($product);
        $this->fakeHttp(['api.paystack.co/refund' => \Illuminate\Support\Facades\Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7004, 'status' => 'pending']], 200)]);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $group), ['item_ids' => [$line->id], 'reason' => 'Out of stock after all']);

        $page = $this->get(route('seller.ledger'))->assertOk()->assertSee('Reversal');
        $this->assertSame(2, $page->viewData('entries')->total());
        $this->assertSame(0, app(LedgerService::class)->balance($seller)['ZAR']['held']);
        $this->get(route('seller.ledger', ['status' => 'reversed']))->assertOk();
    }

    public function test_the_ledger_file_has_the_sellers_entries_only(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['price' => 200]);
        [$order] = $this->paidParcel($product);
        [$other, $theirs] = $this->shippingSeller([], ['price' => 321]);
        [$theirOrder] = $this->paidParcel($theirs);

        $csv = $this->actingAs($seller)->get(route('seller.ledger.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString($order->reference(), $csv);
        $this->assertStringNotContainsString($theirOrder->reference(), $csv);
        $this->assertSame(2, count(array_filter(explode("\n", trim($csv)))));
    }

    // ---- questions and reviews -----------------------------------------------------------------------------------------------

    public function test_a_seller_answers_a_question_and_the_asker_is_told(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $asker = $this->makeBuyer();
        $question = app(ProductQuestions::class)->ask($asker, $product, 'Does this kettle switch itself off?');

        $this->actingAs($seller)->get(route('seller.feedback'))->assertOk()->assertSee('Does this kettle switch itself off?')->assertSee('Post answer');
        $this->post(route('seller.questions.answer', $question), ['body' => ''])->assertSessionHasErrors('body');
        $this->post(route('seller.questions.answer', $question), ['body' => 'Yes, after it boils.'])->assertRedirect(route('seller.feedback', ['tab' => 'questions']));

        $this->assertSame(1, $question->fresh()->answers_count);
        $this->assertTrue($question->answers()->first()->is_seller);
        $this->assertSame(1, $asker->notifications()->count());
        $this->get(route('seller.feedback'))->assertSee('Yes, after it boils.')->assertDontSee('Post answer');
    }

    public function test_a_review_gets_one_reply_that_cannot_be_edited_and_no_review_can_be_hidden_by_the_seller(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $review = Review::factory()->create(['product_id' => $product->id, 'rating' => 2, 'body' => 'The lid broke in a week.']);
        $this->actingAs($seller);

        $page = $this->get(route('seller.feedback', ['tab' => 'reviews']))->assertOk()->assertSee('The lid broke in a week.')->assertSee('One reply per review')->assertSee('cannot edit or delete it');
        $page->assertDontSee('Delete review')->assertDontSee('Hide review');

        $this->post(route('seller.reviews.reply', $review), ['body' => str_repeat('x', 1001)])->assertSessionHasErrors('body');
        $this->post(route('seller.reviews.reply', $review), ['body' => 'Sorry about that. We have sent you a new lid.'])->assertRedirect();
        $this->assertSame(1, ReviewResponse::where('review_id', $review->id)->count());

        $this->post(route('seller.reviews.reply', $review), ['body' => 'Second try.'])->assertSessionHasErrors('body');
        $this->assertSame('Sorry about that. We have sent you a new lid.', ReviewResponse::where('review_id', $review->id)->value('body'));
        $this->get(route('seller.feedback', ['tab' => 'reviews']))->assertSee('Sorry about that.')->assertSee('It cannot be edited');

        $routes = collect(\Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_starts_with($u, 'sell/reviews') || str_starts_with($u, 'sell/questions'));
        $this->assertSame(['sell/questions/{question}/answer', 'sell/reviews', 'sell/reviews/{review}/reply'], $routes->sort()->values()->all(), 'there is no route to delete or hide a review or question');
    }

    public function test_reports_against_the_stores_reviews_are_listed_without_who_made_them(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $reporter = $this->makeBuyer();
        $review = Review::factory()->create(['product_id' => $product->id]);
        ContentReport::create(['reportable_type' => 'review', 'reportable_id' => $review->id, 'reporter_id' => $reporter->id, 'reason' => 'fake', 'details' => 'Private words from the reporter']);
        $elsewhere = Review::factory()->create(['product_id' => $this->makeListing($this->makeSeller())->id]);
        ContentReport::create(['reportable_type' => 'review', 'reportable_id' => $elsewhere->id, 'reporter_id' => $reporter->id, 'reason' => 'spam']);

        $page = $this->actingAs($seller)->get(route('seller.feedback', ['tab' => 'reports']))->assertOk()->assertSee('Looks fake')->assertSee('With the Emall team')->assertDontSee($reporter->name)->assertDontSee('Private words from the reporter')->assertDontSee('Spam');
        $this->assertSame(1, $page->viewData('reports')->count());
    }

    // ---- dashboard -----------------------------------------------------------------------------------------------------------

    public function test_the_dashboard_leads_with_what_needs_the_seller_each_item_counted_from_real_rows(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['stock' => 3, 'low_stock_threshold' => 5, 'name' => 'Dash Kettle']);
        [$order, $group, $line, $buyer] = $this->paidParcel($kettle);                                         // 1 to ship
        $this->makeListing($seller, ['status' => 'rejected', 'rejected_reason' => 'Blurry photo.', 'name' => 'Dash Rejected Lamp']);
        $this->makeListing($seller, ['status' => 'pending_approval']);
        app(ProductQuestions::class)->ask($this->makeBuyer(), $kettle, 'Is this kettle cordless?');            // 1 question
        $review = Review::factory()->create(['product_id' => $kettle->id]);                                    // 1 review to reply to

        $page = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();

        $page->assertSee('1 parcel to pack and ship')->assertSee('1 question to answer')->assertSee('1 review without a reply')->assertSee('Dash Rejected Lamp was rejected')->assertSee('Blurry photo.')
            ->assertSee('Dash Kettle (2 left)')->assertSee('1 listing is waiting for the Emall team to review');
        $needs = $page->viewData('needs');
        $this->assertSame([1, 0, 1, 1, 1], [$needs['toShip'], $needs['returns'], $needs['lowStock'], $needs['questions'], $needs['reviews']]);
        $this->assertSame(1, $needs['rejected']->count());

        // Doing the work clears the list.
        $this->post(route('seller.orders.ship', $group), ['carrier' => 'courier_guy']);
        $this->post(route('seller.reviews.reply', $review), ['body' => 'Thank you for the review.']);
        $needs = $this->get(route('seller.dashboard'))->viewData('needs');
        $this->assertSame([0, 0], [$needs['toShip'], $needs['reviews']]);
    }

    public function test_a_switched_off_listing_is_not_counted_as_low_stock(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['stock' => 1, 'is_active' => false]);
        $live = $this->makeListing($seller, ['stock' => 1, 'name' => 'Dash Live Low Kettle']);

        $needs = $this->actingAs($seller)->get(route('seller.dashboard'))->viewData('needs');

        $this->assertSame(1, $needs['lowStock']);
        $this->assertSame('Dash Live Low Kettle', $needs['lowStockSample']->first()->product->name);
    }

    public function test_the_dashboard_numbers_come_from_the_ledger_and_orders_with_definitions_and_never_from_unpaid_orders(): void
    {
        config(['emall.commission.percent' => 5]);
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 200, 'stock' => 20]);
        $this->paidParcel($kettle);
        $this->enablePaystack();
        $this->placeOrder($this->makeBuyer(), [[$kettle, 1]]); // placed, unpaid

        $page = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();

        $page->assertSee('R200.00')->assertSee('from 1 order and 1 item')->assertSee('Held, waiting out the return window')->assertSee('R250.00')
            ->assertSee('1 parcel is waiting for the buyer to pay: do not ship it yet')->assertSee('items after any discount, VAT included, delivery left out');
        $this->assertSame(20000, $page->viewData('sales')['sales_cents']);
    }

    public function test_response_times_are_the_median_over_real_answers_and_absent_without_any(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertSee('No data yet: you have not answered a question');

        Carbon::setTestNow('2026-03-02 08:00:00');
        $q = app(ProductQuestions::class)->ask($this->makeBuyer(), $product, 'Is the cord long enough for us?');
        Carbon::setTestNow('2026-03-02 14:00:00');
        app(ProductQuestions::class)->answer($seller, $q, 'About 1.2 metres.');
        Carbon::setTestNow();

        $r = $this->get(route('seller.dashboard'))->viewData('responses');
        $this->assertSame([6.0, 1], [$r['questions']['hours'], $r['questions']['sample']]);
        $this->get(route('seller.dashboard'))->assertSee('6 hours')->assertSee('middle value of 1 question');
    }

    public function test_the_dashboard_period_switch_changes_the_window_and_unknown_values_fall_back(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->get(route('seller.dashboard', ['period' => 7]))->assertSee('Last 7 days');
        $this->get(route('seller.dashboard', ['period' => 'forever']))->assertOk()->assertSee('Last 30 days');
    }
}
