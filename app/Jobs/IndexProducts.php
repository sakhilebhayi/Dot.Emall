<?php

namespace App\Jobs;

use App\Services\Search\SearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-indexes some products' search documents after their text changed. Failing here must never hurt the edit that
 * caused it: the scheduled stale sweep (emall:search-reindex --stale) picks up anything this misses.
 */
class IndexProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @param  array<int, int>  $productIds */
    public function __construct(public array $productIds) {}

    public function handle(SearchIndexer $indexer): void
    {
        try {
            $indexer->index($this->productIds);
        } catch (Throwable $e) {
            Log::warning('Search indexing failed; the stale sweep will retry.', ['error' => class_basename($e), 'products' => count($this->productIds)]);
        }
    }
}
