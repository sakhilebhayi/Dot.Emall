<?php

namespace App\Observers;

use App\Contracts\CategoryTree;
use App\Jobs\IndexProducts;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Search\SearchIndexer;
use App\Services\Search\Vocabulary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps search documents current when the TEXT of a listing changes: its name, description, category or SKU, its
 * store's name, a category's name. Price, stock, visibility and rating are read live by the search and need nothing.
 * Registered for Product, Store and StoreCategory by DiscoveryServiceProvider.
 */
class SearchIndexObserver
{
    private const INLINE_LIMIT = 25;

    private static ?bool $ready = null;

    public function saved(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($model instanceof Product) {
            if ($model->wasRecentlyCreated || $model->wasChanged(['name', 'description', 'category_id', 'store_id', 'sku'])) {
                $this->dispatch([$model->id]);
            }

            return;
        }

        if ($model instanceof Store && $model->wasChanged(['name', 'region', 'province'])) {
            $this->dispatch(DB::table('products')->where('store_id', $model->id)->whereNull('deleted_at')->pluck('id')->all());

            return;
        }

        if ($model instanceof StoreCategory) {
            // The tree is read once per request and remembered: a changed category must not be served stale.
            app(CategoryTree::class)->forget();
            Vocabulary::forget();
        }

        if ($model instanceof StoreCategory && ($model->wasChanged(['name', 'parent_id']))) {
            $this->dispatch(DB::table('products')->where('category_id', $model->id)->whereNull('deleted_at')->pluck('id')->all());
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Product && $this->enabled()) {
            try {
                app(SearchIndexer::class)->remove($model->id);
            } catch (Throwable) {
            }
        }
    }

    public function restored(Model $model): void
    {
        if ($model instanceof Product && $this->enabled()) {
            $this->dispatch([$model->id]);
        }
    }

    /**
     * A few listings are re-indexed right after the change commits (about eight small queries each, on a seller's
     * edit, never on a shopper's request). A rename that touches many listings is queued instead.
     *
     * @param  array<int, int>  $ids
     */
    private function dispatch(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            if (count($ids) > self::INLINE_LIMIT) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    IndexProducts::dispatch($chunk)->afterCommit();
                }

                return;
            }

            DB::afterCommit(function () use ($ids) {
                try {
                    app(SearchIndexer::class)->index($ids);
                } catch (Throwable $e) {
                    Log::warning('Search indexing failed; the stale sweep will retry.', ['error' => class_basename($e)]);
                }
            });
        } catch (Throwable) {
            // The stale sweep covers it.
        }
    }

    private function enabled(): bool
    {
        if (! config('discovery.search.observe', true)) {
            return false;
        }

        try {
            return self::$ready ??= Schema::hasTable('search_documents');
        } catch (Throwable) {
            return false;
        }
    }

    /** Tests that rebuild the schema need the table check redone. */
    public static function reset(): void
    {
        self::$ready = null;
    }
}
