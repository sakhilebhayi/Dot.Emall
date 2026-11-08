{{-- A shared wishlist (R-43 W4): products only. No owner, no notes, not indexed. --}}
<x-store-layout robots="noindex">
    <x-slot:title>A shared wishlist</x-slot:title>
    <x-slot:heading>A shared wishlist</x-slot:heading>
    <x-slot:lead>Someone shared these products with you. Prices and stock are today's.</x-slot:lead>

    @if ($products->isEmpty())
        <x-ds.empty-state title="Nothing to show" icon="heart">None of the products on this list can be bought right now.</x-ds.empty-state>
    @else
        <div class="ds-grid">
            @foreach ($products as $p)
                <x-ds.product-card :href="route('products.show', $p)" :title="$p->name" :image="$p->images->first()?->url()" :store="$p->store->name" :amount="$p->price" :rating-value="$p->rating_average ?? 0" :rating-count="$p->rating_count ?? 0" :stock="$p->stock">
                    <x-slot:wishlist><x-wish-toggle :product="$p" /></x-slot:wishlist>
                </x-ds.product-card>
            @endforeach
        </div>
    @endif
</x-store-layout>
