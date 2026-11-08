<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\DB;

/**
 * Precomputes the co-purchase and co-view links into product_relations. Scheduled (emall:recommendations-rebuild);
 * safe to run at any time and any number of times: each run replaces the links of a kind in one transaction.
 *
 * bought_together   distinct counted orders (App\Services\Discovery\CountedOrders) containing both products, over the
 *                   last `window_days`, ignoring orders with more lines than `max_lines_per_order` (a bulk order says
 *                   little about which two items go together). Kept when at least `bought_together_min` orders agree.
 * viewed_together   distinct people whose recently viewed list (product_views) holds both. Kept at `viewed_together_min`.
 *
 * Both are stored in both directions (A->B and B->A) so a product page is one indexed lookup.
 */
class RecommendationBuilder
{
    public function __construct(private readonly CountedOrders $counted) {}

    /** @return array{bought_together: int, viewed_together: int} */
    public function rebuild(): array
    {
        $result = [
            'bought_together' => $this->boughtTogether(),
            'viewed_together' => $this->viewedTogether(),
        ];

        Recommender::forgetCache();

        return $result;
    }

    public function boughtTogether(): int
    {
        $window = now()->subDays((int) config('discovery.recommendations.window_days', 180));
        $min = (int) config('discovery.recommendations.bought_together_min', 3);
        $maxLines = (int) config('discovery.recommendations.max_lines_per_order', 30);

        $lines = fn () => $this->counted->lines($window)->whereNotNull('oi.product_id')
            ->whereIn('oi.order_id', DB::table('order_items')->select('order_id')->groupBy('order_id')->havingRaw('count(*) <= ?', [$maxLines]))
            ->select('oi.order_id', 'oi.product_id')->distinct();

        $pairs = DB::query()->fromSub($lines(), 'a')
            ->joinSub($lines(), 'b', fn ($join) => $join->on('a.order_id', '=', 'b.order_id')->on('a.product_id', '<', 'b.product_id'))
            ->groupBy('a.product_id', 'b.product_id')
            ->havingRaw('count(*) >= ?', [$min])
            ->selectRaw('a.product_id as p, b.product_id as q, count(*) as n')
            ->get();

        return $this->store('bought_together', $pairs->map(fn ($r) => [(int) $r->p, (int) $r->q, (int) $r->n])->all());
    }

    public function viewedTogether(): int
    {
        $window = now()->subDays((int) config('discovery.recommendations.window_days', 180));
        $min = (int) config('discovery.recommendations.viewed_together_min', 5);

        $views = fn () => DB::table('product_views')->where('viewed_at', '>=', $window)->select('actor_hash', 'product_id');

        $pairs = DB::query()->fromSub($views(), 'a')
            ->joinSub($views(), 'b', fn ($join) => $join->on('a.actor_hash', '=', 'b.actor_hash')->on('a.product_id', '<', 'b.product_id'))
            ->groupBy('a.product_id', 'b.product_id')
            ->havingRaw('count(*) >= ?', [$min])
            ->selectRaw('a.product_id as p, b.product_id as q, count(*) as n')
            ->get();

        return $this->store('viewed_together', $pairs->map(fn ($r) => [(int) $r->p, (int) $r->q, (int) $r->n])->all());
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $pairs
     */
    private function store(string $kind, array $pairs): int
    {
        $now = now();
        $rows = [];

        foreach ($pairs as [$a, $b, $support]) {
            $rows[] = ['product_id' => $a, 'related_product_id' => $b, 'kind' => $kind, 'support' => $support, 'computed_at' => $now];
            $rows[] = ['product_id' => $b, 'related_product_id' => $a, 'kind' => $kind, 'support' => $support, 'computed_at' => $now];
        }

        // Replaced in one transaction, so a reader sees the old links or the new ones, never half of each.
        DB::transaction(function () use ($kind, $rows) {
            DB::table('product_relations')->where('kind', $kind)->delete();

            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table('product_relations')->insert($chunk);
            }
        });

        return count($rows);
    }
}
