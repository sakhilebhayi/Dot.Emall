{{--
  disc-filters: the filter form of the search and category pages. A plain GET form: it works with no script, and the same markup is drawn twice (the desktop panel and the phone
  bottom sheet), so every id carries the uid.
  Props: search (App\Services\Search\SearchRequest), result (SearchResult), links (App\Support\SearchLinks), uid ('panel' or 'sheet'),
         categories=[] (radio options; empty on a category page), mine=null (the shopper's province, for the "sellers near you" row).
  Every option and count is read from result->facets, which the engine computed from the real catalogue; a group with nothing to choose from is not drawn.
--}}
@props(['search', 'result', 'links', 'uid' => 'panel', 'categories' => [], 'mine' => null])
@php
    $f = $result->facets;
    $money = fn ($n) => 'R'.number_format((float) $n, 0, '.', ',');
    $brandOptions = array_map(fn ($b) => ['value' => $b['value'], 'label' => $b['label'], 'count' => $b['count'], 'checked' => $b['selected']], $f['brands'] ?? []);
    $storeOptions = array_map(fn ($s) => ['value' => $s['id'], 'label' => $s['name'], 'count' => $s['count'], 'checked' => $s['selected']], $f['stores'] ?? []);
    $regionOptions = array_map(fn ($r) => ['value' => $r['value'], 'label' => $r['value'] === $mine ? $r['value'].' (your province)' : $r['value'], 'count' => $r['count'], 'checked' => $r['selected']], $f['regions'] ?? []);
    $ratingOptions = array_merge([['value' => '', 'label' => 'Any rating', 'checked' => $search->minRating === null]], array_map(fn ($r) => ['value' => $r['min'], 'label' => $r['min'].' stars and up', 'count' => $r['count'], 'checked' => $r['selected']], array_values(array_filter($f['rating'] ?? [], fn ($r) => $r['count'] > 0))));
    $availability = $f['availability'] ?? null;
    $price = $f['price'] ?? null;
    $carry = $links->carried();
@endphp
<form method="get" action="{{ $links->base() }}" class="disc-filters" id="filters-{{ $uid }}" data-disc-filters>
    @foreach (['q', 'sort', 'view', 'literal'] as $keep)
        @if (isset($carry[$keep]) && $carry[$keep] !== '')<input type="hidden" name="{{ $keep }}" value="{{ $carry[$keep] }}">@endif
    @endforeach

    @if (count($categories) > 1)
        <x-ds.facet-group title="Category" name="category" type="radio" :options="array_merge([['value' => '', 'label' => 'All categories', 'checked' => $search->categoryId === null]], $categories)" :open="true" />
    @endif

    @if ($price && ($price['max'] ?? 0) > ($price['min'] ?? 0) || $search->priceMin !== null || $search->priceMax !== null)
        <details class="ds-facet" open>
            <summary>Price<x-ds.icon name="chevron-down" :size="18" /></summary>
            <div class="ds-facet__body">
                <div class="ds-facet__range">
                    <div class="ds-field"><label class="ds-field__label" for="price_min-{{ $uid }}">Min R</label><input class="ds-input" type="number" inputmode="numeric" min="0" id="price_min-{{ $uid }}" name="price_min" value="{{ $search->priceMin !== null ? (int) $search->priceMin : '' }}" @if ($price) placeholder="{{ (int) $price['min'] }}" @endif></div>
                    <span aria-hidden="true">to</span>
                    <div class="ds-field"><label class="ds-field__label" for="price_max-{{ $uid }}">Max R</label><input class="ds-input" type="number" inputmode="numeric" min="0" id="price_max-{{ $uid }}" name="price_max" value="{{ $search->priceMax !== null ? (int) $search->priceMax : '' }}" @if ($price) placeholder="{{ (int) $price['max'] }}" @endif></div>
                </div>
                @if ($price && count($price['buckets'] ?? []) > 1)
                    <ul class="disc-buckets" aria-label="Price ranges in this result">
                        @foreach ($price['buckets'] as $b)
                            <li><a href="{{ $links->with(['priceMin' => (float) $b['from'], 'priceMax' => (float) $b['to']]) }}">{{ $money($b['from']) }} to {{ $money($b['to']) }}<span class="ds-facet__count">{{ number_format($b['count']) }}</span></a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </details>
    @endif

    @if ($availability && ($availability['out_of_stock'] > 0 || $availability['selected']))
        <x-ds.facet-group title="Availability" name="in_stock" type="checkbox" :options="[['value' => 1, 'label' => 'In stock only', 'count' => $availability['in_stock'], 'checked' => $availability['selected']]]" />
    @endif

    @if (count($ratingOptions) > 1)
        <x-ds.facet-group title="Rating" name="rating" type="radio" :options="$ratingOptions" />
    @endif

    @if (count($brandOptions) > 0)
        <x-ds.facet-group title="Brand" name="brand" type="checkbox" :options="$brandOptions" />
    @endif

    @foreach ($f['attributes'] ?? [] as $key => $attribute)
        @php($options = array_map(fn ($v) => ['value' => $v['value'], 'label' => $v['label'], 'count' => $v['count'], 'checked' => $v['selected']], $attribute['values']))
        <x-ds.facet-group :title="$attribute['label']" :name="'attr['.$key.']'" type="checkbox" :options="$options" :open="$loop->index < 3 || collect($options)->contains('checked', true)" />
    @endforeach

    @if (count($storeOptions) > 1 || ($storeOptions && $storeOptions[0]['checked']))
        <x-ds.facet-group title="Store" name="store" type="checkbox" :options="$storeOptions" :open="false" />
    @endif

    @if (count($regionOptions) > 1 || ($regionOptions && $regionOptions[0]['checked']))
        <x-ds.facet-group title="Ships from" name="region" type="checkbox" :options="$regionOptions" :open="false" />
    @endif

    <div class="disc-filters__actions">
        <x-ds.button type="submit" size="md">Apply filters</x-ds.button>
        <x-ds.button :href="$links->url($search->with(['categoryId' => $links->base() === route('search') ? null : $search->categoryId, 'brands' => [], 'priceMin' => null, 'priceMax' => null, 'inStock' => false, 'minRating' => null, 'storeIds' => [], 'regions' => [], 'attributes' => [], 'page' => 1]))" variant="quiet">Clear filters</x-ds.button>
    </div>
</form>
