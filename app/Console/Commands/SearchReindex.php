<?php

namespace App\Console\Commands;

use App\Services\Search\DefaultSynonyms;
use App\Services\Search\SearchCapabilities;
use App\Services\Search\SearchIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds and repairs the search index.
 *
 *   php artisan emall:search-reindex            rebuild everything: documents, attributes, vocabulary, units sold
 *   php artisan emall:search-reindex --stale    only listings with no document or edited since their document (scheduled)
 *   php artisan emall:search-reindex --status   report the engine and its capabilities, index nothing
 *   php artisan emall:search-reindex --synonyms add any missing default synonyms
 */
class SearchReindex extends Command
{
    protected $signature = 'emall:search-reindex
        {--stale : Only listings missing from the index or edited since they were indexed}
        {--status : Show which search engine this database supports and how many documents exist}
        {--synonyms : Add any missing default synonyms and stop}';

    protected $description = 'Rebuild the search index (documents, attributes, vocabulary)';

    public function handle(SearchIndexer $indexer, SearchCapabilities $capabilities): int
    {
        $capabilities->forget();

        if ($this->option('status')) {
            foreach ($capabilities->report() as $key => $value) {
                $this->line(str_pad($key, 12).' '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
            }

            $this->line(str_pad('documents', 12).' '.DB::table('search_documents')->count());

            return self::SUCCESS;
        }

        if ($this->option('synonyms')) {
            $this->info('Added '.DefaultSynonyms::seed().' synonyms.');

            return self::SUCCESS;
        }

        if ($this->option('stale')) {
            $changed = $indexer->reindexStale();
            $this->info("Re-indexed {$changed} listings.");

            return self::SUCCESS;
        }

        [$indexed, $removed] = $indexer->rebuild(fn (int $n) => $this->output->isVerbose() ? $this->line("  {$n} indexed") : null);
        $this->info("Indexed {$indexed} listings, removed {$removed} stale documents. Engine: ".$capabilities->report()['engine'].'.');

        return self::SUCCESS;
    }
}
