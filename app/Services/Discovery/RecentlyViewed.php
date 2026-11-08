<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Recently viewed", per person. One row per person per product (viewing again moves it to the top and counts the
 * view), trimmed to the newest 20, private to the person. A visitor's list follows them when they sign in
 * (merge()). A seller looking at their own listing is not a view.
 */
class RecentlyViewed
{
    public function __construct(private readonly Track $track) {}

    /** Records the view in the person's list and in the funnel log. */
    public function view(Actor $actor, Product $product, string $source = 'direct'): void
    {
        if (! $actor->isIdentifiable()) {
            return;
        }

        if ($actor->userId !== null && $this->owns($actor->userId, $product)) {
            return;
        }

        $this->touch($actor->hash(), $product->id);
        $this->track->productView($actor, $product, $source);
    }

    /**
     * The newest views that can still be bought, newest first.
     *
     * @return Collection<int, Product>
     */
    public function for(Actor $actor, int $limit = 10, ?int $excluding = null): Collection
    {
        $hash = $actor->hash();

        if ($hash === null) {
            return collect();
        }

        $views = DB::table('product_views')->where('actor_hash', $hash)
            ->when($excluding, fn ($q) => $q->where('product_id', '!=', $excluding))
            ->orderByDesc('viewed_at')->orderByDesc('id')->limit(((int) config('discovery.views.keep_per_person', 20)))
            ->pluck('viewed_at', 'product_id');

        if ($views->isEmpty()) {
            return collect();
        }

        $products = Product::query()->browsable()->where('stock', '>', 0)
            ->whereIn('id', $views->keys())
            ->when($actor->userId, fn ($q) => $q->whereNotIn('store_id', DB::table('stores')->where('owner_id', $actor->userId)->select('id')))
            ->with(['store', 'images'])->get()->keyBy('id');

        return $views->keys()->map(fn ($id) => $products->get($id))->filter()->take($limit)->values();
    }

    /** @return Collection<int, int> product id => when */
    public function viewedIds(Actor $actor, int $limit = 20): Collection
    {
        $hash = $actor->hash();

        return $hash === null ? collect() : DB::table('product_views')->where('actor_hash', $hash)->orderByDesc('viewed_at')->orderByDesc('id')->limit($limit)->pluck('viewed_at', 'product_id');
    }

    /**
     * Moves a visitor's views (and searches) onto the account they just signed in to.
     *
     * @return int views moved
     */
    public function merge(string $token, int $userId): int
    {
        $from = Actor::hashForToken($token);
        $to = Actor::hashForUser($userId);
        $moved = 0;

        DB::transaction(function () use ($from, $to, &$moved) {
            foreach (DB::table('product_views')->where('actor_hash', $from)->get() as $row) {
                $existing = DB::table('product_views')->where('actor_hash', $to)->where('product_id', $row->product_id)->first();

                if ($existing) {
                    DB::table('product_views')->where('id', $existing->id)->update([
                        'view_count' => (int) $existing->view_count + (int) $row->view_count,
                        'viewed_at' => max($existing->viewed_at, $row->viewed_at),
                    ]);
                } else {
                    DB::table('product_views')->where('id', $row->id)->update(['actor_hash' => $to]);
                }

                $moved++;
            }

            DB::table('product_views')->where('actor_hash', $from)->delete();
            DB::table('search_queries')->where('actor_hash', $from)->update(['actor_hash' => $to]);
            $this->trim($to);
        });

        return $moved;
    }

    public function forget(Actor $actor): int
    {
        $hash = $actor->hash();

        return $hash === null ? 0 : DB::table('product_views')->where('actor_hash', $hash)->delete();
    }

    public function prune(?int $days = null): int
    {
        return DB::table('product_views')->where('viewed_at', '<', now()->subDays($days ?? (int) config('discovery.retention.product_views_days', 90)))->delete();
    }

    private function touch(string $hash, int $productId): void
    {
        $now = now();
        $update = fn () => DB::table('product_views')->where('actor_hash', $hash)->where('product_id', $productId)
            ->update(['viewed_at' => $now, 'view_count' => DB::raw('view_count + 1')]);

        if ($update() === 0) {
            // insert-or-ignore (a refused insert would poison a PostgreSQL transaction), then count the view if another
            // request got there first.
            $inserted = DB::table('product_views')->insertOrIgnore(['actor_hash' => $hash, 'product_id' => $productId, 'view_count' => 1, 'viewed_at' => $now]);

            if ($inserted === 0) {
                $update();
            }
        }

        $this->trim($hash);
    }

    private function trim(string $hash): void
    {
        $keep = (int) config('discovery.views.keep_per_person', 20);

        $stale = DB::table('product_views')->where('actor_hash', $hash)
            ->orderByDesc('viewed_at')->orderByDesc('id')->offset($keep)->limit(1000)->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('product_views')->whereIn('id', $stale)->delete();
        }
    }

    private function owns(int $userId, Product $product): bool
    {
        return DB::table('stores')->where('id', $product->store_id)->where('owner_id', $userId)->exists();
    }
}
