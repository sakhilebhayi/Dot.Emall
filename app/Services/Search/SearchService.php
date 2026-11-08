<?php

namespace App\Services\Search;

use App\Services\Discovery\SearchLog;
use App\Services\Discovery\Track;
use App\Support\Actor;

/**
 * The call the storefront makes: run the search, remember it (thinly, see SearchLog) and note it for the funnel
 * metrics. Only a first page with words in it is logged, so paging and filtering do not inflate anything.
 */
class SearchService
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchLog $log,
        private readonly Track $track,
    ) {}

    public function search(SearchRequest $request, ?Actor $actor = null): SearchResult
    {
        $result = $this->engine->search($request);

        if ($actor !== null && $request->text !== '' && $request->page === 1) {
            $this->log->record($actor, $request->text, $result->total);
            $this->track->search($actor, $result->total);
        }

        return $result;
    }

    public function engine(): SearchEngine
    {
        return $this->engine;
    }
}
