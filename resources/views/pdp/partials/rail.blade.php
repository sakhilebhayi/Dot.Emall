{{--
  A row of recommended products with the real reason and number behind it. Variables: $set (App\Services\Discovery\RecommendationSet), $id (anchor), $bundle (null, or the combined add-to-cart data from ProductPage::bundle()), $product, $variant.
  Replace with <x-disc-rail> when that component is merged.
--}}
<section class="pdp-section pdp-rail" id="{{ $id }}" aria-labelledby="{{ $id }}-h">
    <div class="pdp-section__head">
        <div>
            <h2 id="{{ $id }}-h">{{ $set->title }}@if ($set->editorial) <x-ds.badge tone="info" class="pdp-rail__badge">Our suggestion</x-ds.badge>@endif</h2>
            <p class="ds-section__lead">{{ $set->basis }}</p>
        </div>
    </div>
    <ul class="pdp-rail__track">
        @foreach ($set->items as $item)
            @php($p = $item->product)
            <li class="pdp-rail__item">
                <x-ds.product-card :href="route('products.show', ['product' => $p, 'src' => 'recommendation'])" :title="$p->name" :image="$p->images->first()?->cardUrl()" :store="$p->store->name" :amount="$p->price"
                    :rating-value="$p->rating_average ?? 0" :rating-count="$p->rating_count ?? 0" :stock="$p->stock" ratio="1 / 1">
                    <x-slot:wishlist><x-wish-toggle :product="$p" /></x-slot:wishlist>
                </x-ds.product-card>
                <p class="pdp-rail__why">{{ $item->basis }}</p>
            </li>
        @endforeach
    </ul>
    @if (! empty($bundle))
        <form method="POST" action="{{ route('products.bundle', $product) }}" class="pdp-bundle" data-pdp-bundle>
            @csrf
            <input type="hidden" name="variant_id" value="{{ $variant?->id }}" data-bundle-variant>
            @foreach ($bundle['ids'] as $bid)<input type="hidden" name="items[]" value="{{ $bid }}">@endforeach
            <p class="pdp-bundle__text">
                <strong>{{ $bundle['count'] }} {{ $bundle['count'] === 1 ? 'item' : 'items' }} together</strong>: this one and {{ count($bundle['ids']) }} of the products above, {{ $bundle['total_from'] ? 'from ' : '' }}<x-ds.price :cents="$bundle['total']" size="sm" />.
                @if ($bundle['skipped'])<span class="ds-muted"> {{ implode(', ', $bundle['skipped']) }} comes in several options, so you add it on its own page.</span>@endif
                <span class="ds-muted"> Delivery is worked out per seller in your cart.</span>
            </p>
            <x-ds.button type="submit" icon="cart">Add all {{ $bundle['count'] }} to cart</x-ds.button>
        </form>
    @endif
</section>
