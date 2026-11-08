<?php

namespace Tests\Feature\Security;

use App\Services\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-43 security pass. /search is public and every filter value adds work (a join per attribute, a chip per value), so a request that
 * names 300 attributes took nine seconds and returned 2.6 MB. The number of filters a request may carry is bounded where it is read.
 */
class SearchInputLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_cannot_ask_for_hundreds_of_attribute_filters(): void
    {
        $attr = [];

        foreach (range(1, 300) as $i) {
            $attr["key{$i}"] = ['a', 'b'];
        }

        $request = SearchRequest::fromArray(['q' => 'pot', 'attr' => $attr]);

        $this->assertCount((int) config('discovery.search.max_attribute_filters'), $request->attributes);
        $this->assertLessThanOrEqual(8, config('discovery.search.max_attribute_filters'), 'a handful, as a real filter bar offers');
    }

    public function test_the_values_of_one_filter_are_bounded_too(): void
    {
        $many = array_map(fn ($i) => "v{$i}", range(1, 500));
        $request = SearchRequest::fromArray(['q' => 'pot', 'brand' => $many, 'store' => range(1, 500), 'region' => $many, 'attr' => ['colour' => $many]]);
        $max = (int) config('discovery.search.max_filter_values');

        $this->assertCount($max, $request->brands);
        $this->assertCount($max, $request->storeIds);
        $this->assertCount($max, $request->regions);
        $this->assertCount($max, $request->attributes['colour']);
    }

    public function test_an_ordinary_filtered_search_is_unchanged(): void
    {
        $request = SearchRequest::fromArray(['q' => 'pot', 'brand' => ['Lodge', 'Le Creuset'], 'attr' => ['colour' => ['red', 'blue'], 'material' => ['iron']], 'store' => [3, 4], 'region' => ['Gauteng']]);

        $this->assertSame(['Lodge', 'Le Creuset'], $request->brands);
        $this->assertSame(['colour' => ['red', 'blue'], 'material' => ['iron']], $request->attributes);
        $this->assertSame([3, 4], $request->storeIds);
        $this->assertSame(['Gauteng'], $request->regions);
    }

    public function test_a_public_search_with_an_absurd_number_of_filters_is_answered_quickly(): void
    {
        $query = http_build_query(['q' => 'pot', 'attr' => array_fill_keys(array_map(fn ($i) => "k{$i}", range(1, 300)), ['v'])]);
        $started = microtime(true);

        $this->get('/search?'.$query)->assertOk();

        $this->assertLessThan(3.0, microtime(true) - $started);
    }
}
