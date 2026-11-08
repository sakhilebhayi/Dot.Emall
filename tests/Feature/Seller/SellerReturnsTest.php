<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** The returns queue and one return: the buyer's words, days left, approve, decline with a reason, received with a refund and a restock. */
class SellerReturnsTest extends TestCase
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

    /** A delivered parcel with a return asked for. @return array{0: \App\Models\User, 1: \App\Models\Product, 2: \App\Models\ReturnRequest, 3: \App\Models\OrderItem} */
    private function asked(string $reason = 'faulty'): array
    {
        [$seller, $product] = $this->shippingSeller([], ['stock' => 10]);
        [$order, $group, $line, $buyer] = $this->paidParcel($product);
        $this->shipParcel($group);
        $this->advanceAll($order->fresh(), OrderStatus::Delivered);

        return [$seller, $product, $this->returnFor($line, $buyer, $reason, 'The lid does not close properly'), $line];
    }

    public function test_the_queue_is_sorted_by_state_and_shows_days_left_to_answer(): void
    {
        [$seller, , $return] = $this->asked();

        $page = $this->actingAs($seller)->get(route('seller.returns'))->assertOk()->assertSee('Faulty or damaged')->assertSee('10 days left to answer')->assertSee('Answer');
        $this->assertSame(1, $page->viewData('counts')['to_answer']);
        $this->assertSame(0, $page->viewData('counts')['approved']);

        $this->get(route('seller.returns', ['tab' => 'approved']))->assertOk()->assertDontSee('Faulty or damaged')->assertSee('No returns here');
        $this->get(route('seller.returns', ['tab' => 'nonsense']))->assertOk()->assertSee('10 days left to answer');
    }

    public function test_the_detail_shows_the_buyers_reason_who_pays_the_refund_and_a_plain_countdown(): void
    {
        [$seller, , $return] = $this->asked();

        $this->actingAs($seller)->get(route('seller.returns.show', $return))->assertOk()
            ->assertSee('The lid does not close properly')->assertSee('Faulty or damaged')->assertSee('You do: this reason means the item was not right')
            ->assertSee('R100.00')->assertSee('Delivery is not refunded')->assertSee('10 days left to answer, then it goes to the Emall team')->assertSee('attached no photos');

        Carbon::setTestNow(now()->addDays(9));
        $this->get(route('seller.returns.show', $return))->assertSee('1 day left to answer');
        Carbon::setTestNow(now()->addDays(1));
        $this->get(route('seller.returns.show', $return))->assertSee('Answer today');
    }

    public function test_a_change_of_mind_is_the_buyers_cost(): void
    {
        [$seller, , $return] = $this->asked('changed_mind');

        $this->actingAs($seller)->get(route('seller.returns.show', $return))->assertSee('The buyer does: they changed their mind');
    }

    public function test_approving_moves_the_return_on_and_the_goods_are_awaited(): void
    {
        [$seller, , $return] = $this->asked();

        $this->actingAs($seller)->post(route('seller.returns.approve', $return), ['note' => 'Send it to our Cape Town depot'])->assertRedirect(route('seller.returns.show', $return));

        $return->refresh();
        $this->assertSame([ReturnStatus::Approved, 'Send it to our Cape Town depot'], [$return->status, $return->seller_response]);
        $this->assertSame(1, $this->get(route('seller.returns', ['tab' => 'approved']))->viewData('returns')->count());
        $this->get(route('seller.returns.show', $return))->assertSee('When the goods arrive')->assertSee('Put the item back in stock');
        $this->post(route('seller.returns.approve', $return))->assertSessionHasErrors('status'); // answered once
    }

    public function test_declining_needs_a_reason_and_the_reason_is_kept(): void
    {
        [$seller, , $return] = $this->asked();
        $this->actingAs($seller);

        $this->post(route('seller.returns.decline', $return), [])->assertSessionHasErrors('reason');
        $this->post(route('seller.returns.decline', $return), ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);

        $this->post(route('seller.returns.decline', $return), ['reason' => 'The damage was caused after delivery, see the photo you sent.'])->assertRedirect();
        $return->refresh();
        $this->assertSame([ReturnStatus::Declined, 'The damage was caused after delivery, see the photo you sent.'], [$return->status, $return->seller_response]);
        $this->get(route('seller.returns.show', $return))->assertSee('You declined this')->assertSee('The damage was caused after delivery');
    }

    public function test_marking_received_refunds_the_buyer_reverses_the_earnings_and_can_restock(): void
    {
        [$seller, $product, $return, $line] = $this->asked();
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7002, 'status' => 'pending']], 200)]);
        $this->actingAs($seller);
        $stock = $product->fresh()->stock;

        $this->post(route('seller.returns.receive', $return))->assertSessionHasErrors('status'); // not approved yet: nothing to receive
        $this->post(route('seller.returns.approve', $return));
        $this->post(route('seller.returns.receive', $return), ['restock' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(ReturnStatus::Refunded, $return->status);
        $this->assertTrue($return->restock);
        $this->assertSame(OrderStatus::Refunded, $this->lineStatus($line));
        $this->assertSame($stock + 1, $product->fresh()->stock, 'the unit is back on the shelf');
        $this->assertDatabaseHas('refunds', ['order_id' => $return->order_id, 'kind' => 'return', 'amount_cents' => 10000]);
        $this->assertSame(6000, (int) LedgerEntry::query()->where('order_group_id', $line->order_group_id)->sum('gross_cents'), 'the seller keeps the delivery fee, the item is reversed');
        $this->get(route('seller.returns.show', $return))->assertSee('The buyer was refunded R100.00')->assertSee('went back into stock');
    }

    public function test_received_without_restock_leaves_the_stock_alone(): void
    {
        [$seller, $product, $return] = $this->asked();
        $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7003, 'status' => 'pending']], 200)]);
        $stock = $product->fresh()->stock;

        $this->actingAs($seller)->post(route('seller.returns.approve', $return));
        $this->post(route('seller.returns.receive', $return), ['restock' => '0'])->assertRedirect();

        $this->assertFalse($return->fresh()->restock);
        $this->assertSame($stock, $product->fresh()->stock);
    }

    public function test_photos_are_streamed_only_to_the_seller_and_only_from_the_returns_folder(): void
    {
        Storage::fake('local');
        [$seller, , $return] = $this->asked();
        $other = $this->makeSeller();
        Storage::disk('local')->put('returns/'.$return->order_id.'/a.jpg', 'JPEGDATA');
        Storage::disk('local')->put('secrets/private.txt', 'SECRET');
        $return->update(['photo_paths' => ['returns/'.$return->order_id.'/a.jpg', 'secrets/private.txt', 'returns/'.$return->order_id.'/../../secrets/private.txt']]);

        $this->actingAs($seller)->get(route('seller.returns.show', $return))->assertSee('Photo 1 from the buyer')->assertDontSee('Photo 2 from the buyer');
        $this->get(route('seller.returns.photo', [$return, 0]))->assertOk();
        $this->get(route('seller.returns.photo', [$return, 1]))->assertNotFound();
        $this->get(route('seller.returns.photo', [$return, 2]))->assertNotFound();
        $this->get(route('seller.returns.photo', [$return, 7]))->assertNotFound();
        $this->actingAs($other)->get(route('seller.returns.photo', [$return, 0]))->assertNotFound();
    }

    public function test_the_dashboard_counts_returns_waiting_for_an_answer(): void
    {
        [$seller] = $this->asked();

        $this->actingAs($seller)->get(route('seller.dashboard'))->assertSee('1 return to answer')->assertSee('goes to the Emall team in 10 days');
    }
}
