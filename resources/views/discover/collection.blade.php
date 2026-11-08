<x-store-layout :robots="$ended ? 'noindex, follow' : null">
    <x-slot:title>{!! $collection->title !!}</x-slot:title>
    <x-slot:description>{{ $collection->description ? \Illuminate\Support\Str::limit(strip_tags($collection->description), 155) : $collection->title.': a Dot.Emall collection.' }}</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Collections', 'href' => route('collections.index')], ['label' => $collection->title]]" /></x-slot:breadcrumbs>
    <x-slot:heading>{!! $collection->title !!}</x-slot:heading>
    <x-slot:lead>{{ $window }}.</x-slot:lead>

    <div class="disc-collection-head">
        @if ($collection->description)<p class="disc-intro">{{ $collection->description }}</p>@endif
        <p class="disc-muted">
            @if ($collection->type === 'manual')Hand-picked by the Emall team.@else Made by a rule, so it is always current: {{ $ruleText }}.@endif
            @unless ($ended){{ number_format($total) }} {{ \Illuminate\Support\Str::plural('product', $total) }} {{ $collection->type === 'manual' ? 'in it that can be bought now' : 'match right now' }}.@endunless
        </p>
    </div>

    @if ($ended)
        <x-ds.alert tone="cau" title="This collection has ended">{{ $window }}. Its products are still in the shop.</x-ds.alert>
        <div class="disc-after"><x-ds.button :href="route('collections.index')" variant="quiet">See current collections</x-ds.button> <x-ds.button :href="route('products.index')" variant="quiet">Browse all products</x-ds.button></div>
    @elseif ($products->isNotEmpty())
        <ul class="disc-results disc-results--grid disc-results--bare">
            @foreach ($products as $p)<li><x-disc-card :product="$p" :eager="$loop->index < 4" /></li>@endforeach
        </ul>
        @if ($searchParams && $total > $products->count())
            <p class="disc-more"><a class="ds-btn ds-btn--quiet" href="{{ route('search', $searchParams) }}">See all {{ number_format($total) }} as a search, with filters</a></p>
        @elseif ($collection->type === 'manual' && $total >= 48)
            <p class="disc-muted">Showing the first 48 products.</p>
        @endif
    @else
        <x-ds.empty-state title="Nothing to buy in this collection right now" icon="box">Every product in it is sold out or no longer listed. It will fill again when stock returns.
            <x-slot:actions><x-ds.button :href="route('products.index')" variant="quiet">Browse all products</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @endif
</x-store-layout>
