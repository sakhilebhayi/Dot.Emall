{{-- The store directory (R-43 W4). Data: App\Services\Storefront\StoreDirectory::index(). Public fields only. --}}
<x-store-layout>
    <x-slot:title>Stores</x-slot:title>
    <x-slot:description>The stores that sell on Dot.Emall, with where they are based and what the platform knows about each seller.</x-slot:description>
    <x-slot:heading>Stores</x-slot:heading>
    <x-slot:lead>Every store here sells on Emall. Each page says what we know about the seller and what the seller only claims.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Stores']]" /></x-slot:breadcrumbs>

    <form method="GET" action="{{ route('stores.index') }}" class="st-filters" role="search" aria-label="Find a store">
        <div class="st-filters__row">
            <x-ds.input name="q" id="store-q" label="Store name" type="search" :value="$q" placeholder="Search stores" />
            <x-ds.select name="region" id="store-region" label="Province" placeholder="Anywhere" :selected="$region" :options="array_combine($regions, $regions)" />
            <x-ds.select name="category" id="store-category" label="Sells" placeholder="Anything" :selected="$category['slug'] ?? null" :options="collect($categoryOptions)->pluck('name', 'slug')->all()" />
            <x-ds.select name="sort" id="store-sort" label="Sort by" :selected="$sort" :options="$sorts" />
            <x-ds.button type="submit" icon="search">Find stores</x-ds.button>
        </div>
        @if ($q !== '' || $region !== '' || $category)
            <p><a href="{{ route('stores.index') }}">Clear filters</a></p>
        @endif
    </form>

    @if ($stores->isEmpty())
        <x-ds.empty-state title="No stores match" icon="store">
            @if ($q !== '' || $region !== '' || $category) Try fewer filters, or a different spelling. @else No store has listed anything for sale yet. @endif
            <x-slot:actions><x-ds.button :href="route('stores.index')" variant="quiet">See all stores</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @else
        <p class="ds-toolbar__count" aria-live="polite">{{ number_format($stores->total()) }} {{ \Illuminate\Support\Str::plural('store', $stores->total()) }}</p>
        <ul class="st-grid">
            @foreach ($stores as $store)
                @php($logo = $directory->logoUrl($store))
                <li class="st-card">
                    @if ($logo)<img class="st-card__logo" src="{{ $logo }}" alt="" width="64" height="64" loading="lazy">@else<span class="st-card__logo" aria-hidden="true"><x-ds.icon name="store" :size="28" /></span>@endif
                    <div class="st-card__body">
                        <h2 class="st-card__name"><a href="{{ route('stores.show', $store) }}">{{ $store->name }}</a></h2>
                        <p class="ds-muted">{{ collect([$store->city, $store->region])->filter()->implode(', ') ?: 'Place not given' }}. {{ number_format($store->live_products) }} {{ \Illuminate\Support\Str::plural('product', $store->live_products) }} for sale.</p>
                        @if ($store->shown_rating !== null)
                            <x-ds.rating :value="$store->shown_rating" :count="$store->rated_count" />
                        @elseif ($store->rated_count > 0)
                            <p class="ds-muted">{{ $store->rated_count }} {{ \Illuminate\Support\Str::plural('review', $store->rated_count) }}, too few for a store rating.</p>
                        @else
                            <p class="ds-muted">No reviews yet.</p>
                        @endif
                        @if ($store->description)<p class="st-card__about">{{ \Illuminate\Support\Str::limit($store->description, 140) }}</p>@endif
                        <ul class="st-facts">
                            @foreach ($directory->facts($store) as $fact)<li><x-ds.icon :name="$fact['icon']" :size="16" />{{ $fact['text'] }}</li>@endforeach
                        </ul>
                    </div>
                </li>
            @endforeach
        </ul>
        <x-ds.pagination :paginator="$stores" />
    @endif
</x-store-layout>
