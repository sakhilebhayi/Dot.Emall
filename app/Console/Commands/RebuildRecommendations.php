<?php

namespace App\Console\Commands;

use App\Services\Discovery\RecommendationBuilder;
use App\Services\Search\SearchIndexer;
use Illuminate\Console\Command;

/** Hourly: rewrite the co-purchase and co-view links and refresh the units-sold counts the "best selling" sort uses. */
class RebuildRecommendations extends Command
{
    protected $signature = 'emall:recommendations-rebuild';

    protected $description = 'Recompute bought-together and viewed-together links from real orders and views';

    public function handle(RecommendationBuilder $builder, SearchIndexer $indexer): int
    {
        $result = $builder->rebuild();
        $sold = $indexer->refreshSalesCounts();

        $this->info("Bought together: {$result['bought_together']} links. Viewed together: {$result['viewed_together']} links. Products with sales: {$sold}.");

        return self::SUCCESS;
    }
}
