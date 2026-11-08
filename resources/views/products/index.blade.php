<x-store-layout>
    <x-slot:title>{{ $search !== '' ? 'Results for '.$search : 'All products' }}</x-slot:title>
    <x-slot:description>Browse products from Dot.Emall sellers. Every product shows its price, delivery and total before you pay.</x-slot:description>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Products']]" /></x-slot:breadcrumbs>
    <x-slot:heading>{{ $search !== '' ? 'Results for "'.$search.'"' : 'All products' }}</x-slot:heading>
    <x-slot:lead>{{ number_format($products->total()) }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }} from approved listings.</x-slot:lead>

    <div class="ds-shop">
        <aside class="ds-shop__filters" aria-label="Filters">
            <details class="ds-shop__filters-toggle" @if ($category) open @endif>
                <summary class="ds-btn ds-btn--quiet"><x-ds.icon name="filter" :size="18" />Filters</summary>
                <form method="get" action="{{ route('products.index') }}">
                    @if ($search !== '')<input type="hidden" name="q" value="{{ $search }}">@endif
                    <x-ds.facet-group title="Category" name="category" type="radio" :options="$categories->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'count' => $c->products_count, 'checked' => (string) $category === (string) $c->id])->all()" />
                    <div style="display: flex; gap: var(--s-2); padding-top: var(--s-3);">
                        <x-ds.button type="submit" size="sm">Apply</x-ds.button>
                        <x-ds.button :href="route('products.index', array_filter(['q' => $search]))" variant="quiet" size="sm">Clear</x-ds.button>
                    </div>
                </form>
            </details>
        </aside>

        <div class="ds-shop__results">
            @if ($products->count())
                <div class="ds-grid">
                    @foreach ($products as $product)
                        <x-ds.product-card :href="route('products.show', $product)" :title="$product->name" :image="$product->images->first()?->url()" :store="$product->store->name" :amount="$product->price"
                            :rating-value="$product->rating_average ?? 0" :rating-count="$product->rating_count ?? 0" :stock="$product->stock" :eager="$loop->index < 4" />
                    @endforeach
                </div>
                <div style="margin-top: var(--s-6);"><x-ds.pagination :paginator="$products" /></div>
            @else
                <x-ds.empty-state title="Nothing matches that" icon="search">Try fewer words or a different category. Only approved listings from active stores appear here.
                    <x-slot:actions><x-ds.button :href="route('products.index')" variant="quiet">See all products</x-ds.button></x-slot:actions>
                </x-ds.empty-state>
            @endif
        </div>
    </div>
</x-store-layout>
