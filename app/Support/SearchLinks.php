<?php

namespace App\Support;

use App\Services\Search\SearchRequest;

/**
 * Builds the URLs of one search page: the same page with a filter removed, a sort changed, another page or the other view.
 * State lives in the query string only, so every link is shareable and the page works with no script.
 */
final class SearchLinks
{
    /**
     * @param  string  $base  the page's own URL without a query string
     * @param  array<string, mixed>  $fixed  query values the page keeps whatever the filters say (for example nothing on /search)
     */
    public function __construct(
        private readonly string $base,
        private readonly SearchRequest $request,
        private readonly string $view = 'grid',
        private readonly bool $literal = false,
        private readonly array $fixed = [],
    ) {}

    public function base(): string
    {
        return $this->base;
    }

    public function request(): SearchRequest
    {
        return $this->request;
    }

    /** The URL for a search (this one by default), with page 1 unless the search itself carries a page. */
    public function url(?SearchRequest $search = null, ?string $view = null, ?bool $literal = null): string
    {
        $search ??= $this->request;
        $query = $search->toArray() + [];
        unset($query['category']);

        // A category page carries its category in the path.
        if ($this->fixed === [] && $search->categoryId !== null) {
            $query['category'] = $search->categoryId;
        }

        $view ??= $this->view;
        $literal ??= $this->literal;

        if ($view === 'list') {
            $query['view'] = 'list';
        }

        if ($literal) {
            $query['literal'] = 1;
        }

        return $this->compose($this->base, $query);
    }

    /** Same search, a filter taken off. */
    public function without(string $key): string
    {
        return $this->url($this->request->withoutFilter($key)->with(['page' => 1]));
    }

    /** Same search with the inferred filters switched off ("search the words only"). */
    public function wordsOnly(): string
    {
        return $this->url($this->request->with(['page' => 1]), null, true);
    }

    /** @param  array<string, mixed>  $changes  SearchRequest property => value */
    public function with(array $changes): string
    {
        return $this->url($this->request->with($changes + ['page' => 1]));
    }

    public function view(string $view): string
    {
        return $this->url($this->request, $view);
    }

    /** @param  array<string, mixed>  $query */
    public function compose(string $base, array $query): string
    {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($query === []) {
            return $base;
        }

        // brand[0]=x becomes brand[]=x: the same meaning, a URL a person can read.
        $string = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $base.'?'.$string;
    }

    /** The query (without page) the paginator and forms carry. @return array<string, mixed> */
    public function carried(): array
    {
        $query = $this->request->with(['page' => 1])->toArray();

        if ($this->fixed !== []) {
            unset($query['category']);
        }

        if ($this->view === 'list') {
            $query['view'] = 'list';
        }

        if ($this->literal) {
            $query['literal'] = 1;
        }

        return $query;
    }
}
