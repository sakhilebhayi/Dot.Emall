<?php

namespace App\Services\Operator;

use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Enums\StoreStatus;
use App\Models\ProductQuestion;
use App\Models\Review;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The operator home: everything that is waiting for a person, with how many and how long the oldest has waited. One cheap query each
 * (a count and a minimum), so the page costs the same however long the queues are. A queue that is empty is reported as clear; a source
 * that has never produced a record (the ledger reconciliation before its first run) is reported as unknown, not as fine.
 */
class NeedsAPerson
{
    public function __construct(private readonly ListingFlags $flags, private readonly ReconcileRuns $reconcile) {}

    /**
     * @return list<array{key: string, label: string, count: int|null, oldest: ?CarbonInterface, route: string, note: string, state: string}>
     */
    public function items(): array
    {
        $items = [
            $this->row('products', 'Listings awaiting approval', DB::table('products')->where('status', 'pending_approval')->whereNull('deleted_at'), 'created_at', 'operator.products.index', 'A seller cannot sell it until you decide.'),
            $this->row('stores', 'Stores awaiting review', DB::table('stores')->where('status', StoreStatus::PendingReview->value), 'submitted_at', 'operator.stores.index', 'A seller is waiting to open.'),
            $this->row('returns', 'Returns escalated to you', DB::table('return_requests')->where('status', ReturnStatus::Escalated->value), 'escalated_at', 'operator.returns.index', 'A buyer and a seller disagree, or the seller did not answer.'),
            $this->row('reports', 'Open reports', DB::table('content_reports')->where('status', 'open'), 'created_at', 'operator.reports', 'People reported something. A report hides nothing by itself.', distinct: ['reportable_type', 'reportable_id']),
            $this->row('reviews', 'Reviews held', DB::table('reviews')->where('status', Review::HELD), 'created_at', 'operator.moderation.index', 'Held for a check before they appear.', ['kind' => 'reviews']),
            $this->row('questions', 'Questions held', DB::table('product_questions')->where('status', ProductQuestion::HELD), 'created_at', 'operator.moderation.index', 'Held because they contain a link or contact detail.', ['kind' => 'questions']),
            $this->row('payments', 'Payments needing review', DB::table('payments')->where('status', PaymentAttemptStatus::NeedsReview->value)->whereNull('reviewed_at'), 'created_at', 'operator.payments.index', 'Money arrived that does not match an order.'),
            $this->row('refunds', 'Refunds not going through', DB::table('refunds')->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value]), 'created_at', 'operator.payments.index', 'A buyer is owed money Paystack has not accepted to return.'),
        ];

        $run = $this->reconcile->latest();
        $items[] = $run === null
            ? ['key' => 'ledger', 'label' => 'Ledger reconciliation', 'count' => null, 'oldest' => null, 'route' => 'operator.ledger.index', 'note' => 'It has not run yet, so nobody knows whether the books agree.', 'state' => 'unknown', 'params' => []]
            : ['key' => 'ledger', 'label' => 'Ledger mismatches', 'count' => $run->mismatch_count, 'oldest' => $run->mismatch_count > 0 ? $run->ran_at : null, 'route' => 'operator.ledger.index', 'note' => $run->mismatch_count > 0 ? 'Orders, payments, refunds and the seller ledger disagree (as of the last run).' : 'Everything agreed at the last run, '.$run->ran_at->diffForHumans().'.', 'state' => $run->mismatch_count > 0 ? 'waiting' : 'clear', 'params' => []];

        $flagged = $this->flags->count();
        $items[] = ['key' => 'flags', 'label' => 'Listings the rules flagged', 'count' => $flagged, 'oldest' => null, 'route' => 'operator.flags.index', 'note' => 'A review aid: each shows the rule that raised it.', 'state' => $flagged > 0 ? 'waiting' : 'clear', 'params' => []];

        return $items;
    }

    /** Waiting items first, oldest wait first; then the unknowns; then what is clear. */
    public function sorted(): array
    {
        $rank = fn (array $i) => match ($i['state']) {
            'waiting' => 0,
            'unknown' => 1,
            default => 2,
        };

        $items = $this->items();
        usort($items, fn ($a, $b) => [$rank($a), $a['oldest']?->getTimestamp() ?? PHP_INT_MAX] <=> [$rank($b), $b['oldest']?->getTimestamp() ?? PHP_INT_MAX]);

        return $items;
    }

    public function total(): int
    {
        return (int) collect($this->items())->where('state', 'waiting')->sum(fn ($i) => (int) $i['count']);
    }

    /**
     * @param  array<string, string>  $params
     * @param  list<string>|null  $distinct
     */
    private function row(string $key, string $label, Builder $query, string $ageColumn, string $route, string $note, array $params = [], ?array $distinct = null): array
    {
        $count = $distinct
            ? (int) DB::query()->fromSub((clone $query)->select($distinct)->distinct(), 'd')->count()
            : (int) (clone $query)->count();
        $oldest = $count > 0 ? (clone $query)->min($ageColumn) : null;

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'oldest' => $oldest ? Carbon::parse($oldest) : null,
            'route' => $route,
            'note' => $note,
            'state' => $count > 0 ? 'waiting' : 'clear',
            'params' => $params,
        ];
    }
}
