<?php

namespace App\Services\Community;

use App\Models\Review;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * A store's rating: the average of the published reviews on all its products, from real reviews only. It is shown
 * only from `store_rating_min_reviews` (default 5) reviews, because an average of one or two is an anecdote.
 */
class StoreRatings
{
    public function refresh(int $storeId): void
    {
        $stats = Review::query()
            ->join('products', 'products.id', '=', 'reviews.product_id')
            ->where('products.store_id', $storeId)
            ->where('reviews.status', Review::PUBLISHED)
            ->selectRaw('COUNT(*) as review_count, AVG(reviews.rating) as review_average')
            ->first();

        $values = [
            'average_rating' => round((float) $stats->review_average, 2),
            'review_count' => (int) $stats->review_count,
            'updated_at' => now(),
        ];

        $updated = DB::table('store_rating_aggregates')->where('store_id', $storeId)->update($values);

        if ($updated === 0) {
            DB::table('store_rating_aggregates')->insertOrIgnore(['store_id' => $storeId] + $values);
        }
    }

    /**
     * @return array{average: float|null, count: int, shown: bool, minimum: int}
     */
    public function summary(Store|int $store): array
    {
        $id = $store instanceof Store ? $store->id : $store;

        return $this->summaries([$id])[$id];
    }

    /**
     * The summaries of several stores in ONE query (a list of stores must not ask once per store).
     *
     * @param  iterable<int>  $storeIds
     * @return array<int, array{average: float|null, count: int, shown: bool, minimum: int}>
     */
    public function summaries(iterable $storeIds): array
    {
        $ids = collect($storeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $min = (int) config('discovery.reviews.store_rating_min_reviews', 5);
        $rows = $ids->isEmpty() ? collect() : DB::table('store_rating_aggregates')->whereIn('store_id', $ids)->get()->keyBy('store_id');
        $out = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);
            $count = (int) ($row->review_count ?? 0);

            $out[$id] = [
                'average' => $count >= $min ? (float) $row->average_rating : null,
                'count' => $count,
                'shown' => $count >= $min,
                'minimum' => $min,
            ];
        }

        return $out;
    }

    /** Recomputes every store; returns the number of stores. */
    public function refreshAll(): int
    {
        $count = 0;

        DB::table('stores')->orderBy('id')->select('id')->chunk(200, function ($stores) use (&$count) {
            foreach ($stores as $store) {
                $this->refresh((int) $store->id);
                $count++;
            }
        });

        return $count;
    }
}
