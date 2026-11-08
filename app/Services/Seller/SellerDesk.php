<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalogue\Inventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What needs the seller today, counted from the rows that need them (W6). Nothing here is a total of anything: it is a
 * to-do list. Every count is for ONE store and is read with that store's id.
 */
class SellerDesk
{
    public function __construct(private readonly Inventory $inventory) {}

    /**
     * @return array{
     *   toShip: int, awaitingPayment: int, returns: int, returnsDueDays: ?int, lowStock: int, lowStockSample: Collection,
     *   questions: int, reviews: int, rejected: Collection, pendingListings: int, total: int
     * }
     */
    public function needsYou(Store $store, User $seller): array
    {
        $toShip = OrderGroup::query()->where('store_id', $store->id)
            ->whereHas('order', fn ($q) => $q->fulfillable())
            ->whereHas('items', fn ($q) => $q->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value]))
            ->count();

        $awaitingPayment = OrderGroup::query()->where('store_id', $store->id)
            ->whereHas('order', fn ($q) => $q->where('requires_payment', true)->whereNotIn('payment_status', ['paid', 'partially_refunded', 'refunded'])->where('status', '!=', 'cancelled'))
            ->whereHas('items', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
            ->count();

        $open = ReturnRequest::query()->where('seller_id', $seller->id)->where('status', ReturnStatus::Requested->value);
        $returns = (clone $open)->count();
        $next = (clone $open)->min('respond_by');

        // A listing the seller has switched off is not "low", it is off: the same rule as the analytics page.
        $low = $this->inventory->lowStock($store, 50)->load('product:id,name,low_stock_threshold,is_active')->filter(fn ($v) => $v->product?->is_active)->values();

        $questions = ProductQuestion::query()->published()->where('answers_count', 0)
            ->whereIn('product_id', Product::withTrashed()->where('store_id', $store->id)->select('id'))->count();

        $reviews = Review::query()->published()->whereDoesntHave('response')
            ->whereIn('product_id', Product::withTrashed()->where('store_id', $store->id)->select('id'))->count();

        $rejected = Product::query()->where('store_id', $store->id)->where('status', 'rejected')->orderByDesc('reviewed_at')->limit(5)
            ->get(['id', 'name', 'rejected_reason']);

        $pending = Product::query()->where('store_id', $store->id)->where('status', 'pending_approval')->count();

        return [
            'toShip' => $toShip,
            'awaitingPayment' => $awaitingPayment,
            'returns' => $returns,
            'returnsDueDays' => $next ? max(0, (int) now()->startOfDay()->diffInDays(Carbon::parse($next)->startOfDay(), false)) : null,
            'lowStock' => $low->count(),
            'lowStockSample' => $low->take(3),
            'questions' => $questions,
            'reviews' => $reviews,
            'rejected' => $rejected,
            'pendingListings' => $pending,
            'total' => $toShip + $returns + $low->count() + $questions + $reviews + $rejected->count(),
        ];
    }

    /**
     * The newest parcels of this store for the dashboard: the seller's own slice of each order and nothing else. The buyer is
     * named only while something of theirs is still to pack or send (the same rule as the orders page); no address is read.
     *
     * @return Collection<int, array{group: OrderGroup, order: Order, status: OrderStatus, awaitingPayment: bool, lineCount: int, netCents: int, buyerName: ?string}>
     */
    public function recentParcels(Store $store, int $limit = 6): Collection
    {
        return OrderGroup::query()->where('store_id', $store->id)
            ->with(['order', 'items'])
            ->whereHas('items', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(function (OrderGroup $group) {
                $live = $group->items->reject(fn (OrderItem $l) => $l->isCancelled());
                $needsShipping = $group->order->isFulfillable() && $live->contains(fn (OrderItem $l) => $l->status->needsShipping());

                return [
                    'group' => $group,
                    'order' => $group->order,
                    'status' => OrderStatus::derive($group->items->pluck('status')),
                    'awaitingPayment' => $group->order->isAwaitingPayment(),
                    'lineCount' => $live->count(),
                    'netCents' => (int) $live->sum(fn (OrderItem $l) => $l->netCents()),
                    'buyerName' => $needsShipping ? ($group->order->shipping_name ?: $group->order->user?->name) : null,
                ];
            })->values();
    }

    /**
     * The average of the published reviews on this store's products, with the number behind it. Unlike the shopper-facing
     * store rating there is no minimum: the seller sees their own two reviews as two reviews. Null average when there are none.
     *
     * @return array{average: ?float, count: int}
     */
    public function reviewSummary(Store $store): array
    {
        $row = Review::query()->published()
            ->whereIn('product_id', Product::withTrashed()->where('store_id', $store->id)->select('id'))
            ->selectRaw('COUNT(*) as n, AVG(rating) as avg_rating')->first();

        $n = (int) ($row->n ?? 0);

        return ['average' => $n > 0 ? round((float) $row->avg_rating, 1) : null, 'count' => $n];
    }

    /**
     * Median hours the store took to answer a question, and a review, over the most recent answers. Null when there are none.
     *
     * @return array{questions: array{hours: ?float, sample: int}, reviews: array{hours: ?float, sample: int}}
     */
    public function responseTimes(Store $store): array
    {
        $productIds = Product::withTrashed()->where('store_id', $store->id)->select('id');

        $q = ProductAnswer::query()->where('store_id', $store->id)->where('is_seller', true)
            ->join('product_questions', 'product_questions.id', '=', 'product_answers.question_id')
            ->whereIn('product_questions.product_id', $productIds)
            ->orderByDesc('product_answers.id')->limit(200)
            ->get(['product_questions.id as qid', 'product_questions.created_at as asked', 'product_answers.created_at as answered'])
            ->groupBy('qid')
            ->map(fn ($rows) => max(0, Carbon::parse($rows->first()->asked)->diffInMinutes(Carbon::parse($rows->min('answered')), false)) / 60)
            ->values()->all();

        $r = DB::table('review_responses')->join('reviews', 'reviews.id', '=', 'review_responses.review_id')
            ->where('review_responses.store_id', $store->id)
            ->orderByDesc('review_responses.id')->limit(100)
            ->get(['reviews.created_at as asked', 'review_responses.created_at as answered'])
            ->map(fn ($row) => max(0, Carbon::parse($row->asked)->diffInMinutes(Carbon::parse($row->answered), false)) / 60)->all();

        return [
            'questions' => ['hours' => $this->median($q), 'sample' => count($q)],
            'reviews' => ['hours' => $this->median($r), 'sample' => count($r)],
        ];
    }

    /** @param array<int, float> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
