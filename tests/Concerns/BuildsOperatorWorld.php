<?php

namespace Tests\Concerns;

use App\Models\ContentReport;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use App\Services\Returns\ReturnService;

/**
 * A small marketplace for the operator desk tests: an operator, a seller with a pending and a live listing, a buyer with a delivered
 * order whose return was declined and escalated, a held review, a held question and a reported review.
 */
trait BuildsOperatorWorld
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;

    protected function makeOperator(array $attributes = []): User
    {
        return User::factory()->withPersonalTeam()->create(array_merge(['is_platform_operator' => true], $attributes));
    }

    /** @return array<string, mixed> */
    protected function operatorWorld(): array
    {
        $operator = $this->makeOperator();
        $seller = $this->makeSeller(['name' => 'Ridgeline Outfitters']);
        $this->withDelivery($seller, ['price' => 40]);
        $buyer = $this->makeBuyer();

        $pending = $this->makeListing($seller, ['name' => 'Pendingware kettle', 'status' => 'pending_approval', 'price' => 250]);
        $live = $this->makeListing($seller, ['name' => 'Liveware toaster', 'price' => 300, 'stock' => 9]);

        $this->enablePaystack();
        $order = $this->placeOrder($buyer, [[$live, 1]]);
        $this->payOrder($order);
        $order = $this->deliver($order);
        $line = $order->items()->first();

        $returns = app(ReturnService::class);
        $return = $returns->request($line, $buyer, 'faulty', 'It will not switch on');
        $returns->decline($return, $seller, 'Looks like water damage to us');
        $return = $returns->escalate($return->refresh(), $buyer, 'It was dead when it arrived');

        $reviewer = $this->makeBuyer();
        $held = Review::factory()->create(['product_id' => $live->id, 'user_id' => $reviewer->id, 'rating' => 1, 'body' => 'Call me on 0825551234 for a better deal']);
        $held->forceFill(['status' => Review::HELD, 'held_reason' => 'contact_details'])->save();

        $asker = $this->makeBuyer();
        $question = ProductQuestion::factory()->held()->create(['product_id' => $live->id, 'user_id' => $asker->id, 'body' => 'Buy cheaper at example.test/deal?']);

        $reported = Review::factory()->create(['product_id' => $live->id, 'user_id' => $this->makeBuyer()->id, 'rating' => 5, 'body' => 'Best shop, buy from my cousin']);
        $reporter = $this->makeBuyer();
        $report = ContentReport::query()->create(['reportable_type' => 'review', 'reportable_id' => $reported->id, 'reporter_id' => $reporter->id, 'reason' => 'fake', 'details' => 'Written by the seller']);

        return compact('operator', 'seller', 'buyer', 'pending', 'live', 'order', 'line', 'return', 'held', 'question', 'reported', 'report', 'reporter', 'reviewer', 'asker');
    }
}
