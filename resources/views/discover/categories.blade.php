<x-store-layout>
    <x-slot:title>All categories</x-slot:title>
    <x-slot:description>Every category on Dot.Emall with the number of products you can buy in it today.</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Categories']]" /></x-slot:breadcrumbs>
    <x-slot:heading>All categories</x-slot:heading>
    <x-slot:lead>@if ($total > 0){{ number_format($total) }} products across {{ count($roots) }} {{ \Illuminate\Support\Str::plural('category', count($roots)) }}. A count is what a shopper can buy today.@else Nothing is listed yet.@endif</x-slot:lead>

    @if ($roots)
        <div class="disc-tree">
            @foreach ($roots as $root)
                <section class="disc-tree__root" aria-labelledby="cat-{{ $root['id'] }}">
                    <header>
                        <h2 id="cat-{{ $root['id'] }}"><a href="{{ route('categories.show', $root['slug']) }}">{{ $root['name'] }}</a></h2>
                        <p class="disc-count disc-count--lg">{{ number_format($root['rollup_count']) }} {{ \Illuminate\Support\Str::plural('product', $root['rollup_count']) }}</p>
                        @if (! empty($descriptions[$root['id']]))<p class="disc-muted">{{ $descriptions[$root['id']] }}</p>@endif
                    </header>
                    <ul class="disc-tree__children">
                        @foreach (array_filter($root['children'], fn ($c) => $c['rollup_count'] > 0) as $child)
                            <li>
                                <a class="disc-tree__child" href="{{ route('categories.show', $child['slug']) }}"><span>{{ $child['name'] }}</span><span class="disc-count">{{ number_format($child['rollup_count']) }}</span></a>
                                @php($grand = array_values(array_filter($child['children'], fn ($g) => $g['rollup_count'] > 0)))
                                @if ($grand)
                                    <ul class="disc-tree__grand">
                                        @foreach (array_slice($grand, 0, 5) as $g)<li><a href="{{ route('categories.show', $g['slug']) }}">{{ $g['name'] }} <span class="disc-count">{{ number_format($g['rollup_count']) }}</span></a></li>@endforeach
                                        @if (count($grand) > 5)<li><a href="{{ route('categories.show', $child['slug']) }}">and {{ count($grand) - 5 }} more</a></li>@endif
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @else
        <x-ds.empty-state title="No categories with products yet" icon="grid">A category appears here once a store has an approved listing in it.
            <x-slot:actions><x-ds.button :href="route('products.index')" variant="quiet">See all products</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @endif
</x-store-layout>
