{{--
  disc-card: one product as the discovery pages show it: the kit's product card, then a line saying WHY it is here and, when the seller ships from the shopper's province, a mark saying so.
  Props: product (required, App\Models\Product with store and images loaded), view='grid' | 'row', why=null (one honest sentence: "Matched title and brand", "Bought together in 5 orders"),
         region=null (the province the store ships from), mine=null (the shopper's province; the mark shows only when the two are equal), eager=false.
  Slot wishlist: passed through to the card's wishlist position (the wishlist feature puts its toggle there).
--}}
@props(['product', 'view' => 'grid', 'why' => null, 'region' => null, 'mine' => null, 'eager' => false])
<div {{ $attributes->class(['disc-card', 'disc-card--row' => $view === 'row']) }}>
    @if (isset($wishlist))
        <x-ds.product-card :href="route('products.show', $product)" :title="$product->name" :image="$product->images->first()?->url()" :store="$product->store?->name" :amount="$product->price"
            :rating-value="$product->rating_average ?? 0" :rating-count="$product->rating_count ?? 0" :stock="$product->stock" :variant="$view" :eager="$eager">
            <x-slot:wishlist>{{ $wishlist }}</x-slot:wishlist>
        </x-ds.product-card>
    @else
        <x-ds.product-card :href="route('products.show', $product)" :title="$product->name" :image="$product->images->first()?->url()" :store="$product->store?->name" :amount="$product->price"
            :rating-value="$product->rating_average ?? 0" :rating-count="$product->rating_count ?? 0" :stock="$product->stock" :variant="$view" :eager="$eager" />
    @endif
    @if ($region && $mine && $region === $mine)<p class="disc-card__near"><x-ds.icon name="pin" :size="14" />Ships from your province</p>@endif
    @if ($why)<p class="disc-card__why">{{ $why }}</p>@endif
</div>
