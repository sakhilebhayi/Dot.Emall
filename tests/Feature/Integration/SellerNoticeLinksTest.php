<?php

namespace Tests\Feature\Integration;

use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerService;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Every notice a seller receives opens the seller desk page that has the action (parcel, return, ledger), never the buyer's
 * order page that the notices were written against before the seller desk existed.
 */
class SellerNoticeLinksTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_seller_notice_opens_the_page_on_the_seller_desk_that_has_the_action(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100, 'stock' => 5]), 1]]);
        $this->payOrder($order);
        $group = $order->groups()->firstOrFail();

        $links = fn () => $seller->fresh()->notifications()->get()->mapWithKeys(fn ($n) => [$n->data['type'] => $n->data['url'] ?? null]);

        $this->assertSame(route('seller.orders.show', $group), $links()['new_order']);
        $this->actingAs($seller)->get($links()['new_order'])->assertOk();

        $order = $this->deliver($order);
        $return = app(ReturnService::class)->request($order->items()->firstOrFail(), $buyer, 'faulty', 'broken');
        $this->assertSame(route('seller.returns.show', $return), $links()['return_requested']);
        $this->actingAs($seller)->get($links()['return_requested'])->assertOk();

        app(ReturnService::class)->decline($return->fresh(), $seller, 'It was used');
        app(ReturnService::class)->escalate($return->fresh(), $buyer, 'I disagree');
        $this->assertSame(route('seller.returns.show', $return), $links()['return_escalated']);

        Carbon::setTestNow(now()->addDays(9));
        $return->forceFill(['status' => 'closed'])->save();
        LedgerEntry::query()->where('seller_id', $seller->id)->update(['release_at' => now()->subDay()]);
        app(LedgerService::class)->releaseDue();
        $this->assertSame(route('seller.ledger'), $links()['ledger_released']);
        $this->actingAs($seller)->get($links()['ledger_released'])->assertOk();
    }
}
