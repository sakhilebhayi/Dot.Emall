@php
    $directory = app(\App\Services\Storefront\StoreDirectory::class);
    $logo = $directory->logoUrl($store);
    $place = trim(collect([$store->city, $store->region])->filter()->implode(', '));
@endphp
<section class="pdp-section pdp-seller" id="seller" aria-labelledby="seller-h">
    <div class="pdp-section__head"><h2 id="seller-h">About the seller</h2></div>
    <div class="pdp-seller__grid">
        <div class="pdp-seller__id">
            <div class="pdp-seller__name">
                @if ($logo)<img class="pdp-seller__logo" src="{{ $logo }}" alt="" width="56" height="56" loading="lazy">@else<span class="pdp-seller__logo pdp-seller__logo--none" aria-hidden="true"><x-ds.icon name="store" :size="28" /></span>@endif
                <div>
                    <p class="pdp-seller__title"><a href="{{ route('stores.show', $store) }}">{{ $store->name }}</a></p>
                    @if ($place)<p class="ds-muted">{{ $place }}</p>@endif
                    @if ($storeRating['shown'])
                        <x-ds.rating :value="$storeRating['average']" :count="$storeRating['count']" />
                    @elseif ($storeRating['count'] > 0)
                        <p class="ds-muted">{{ $storeRating['count'] }} {{ \Illuminate\Support\Str::plural('review', $storeRating['count']) }}. A store rating shows from {{ $storeRating['minimum'] }}.</p>
                    @endif
                </div>
            </div>
            @if ($store->description)<p class="pdp-seller__about">{{ \Illuminate\Support\Str::limit($store->description, 260) }}</p>@endif
            <p><x-ds.button :href="route('stores.show', $store)" variant="quiet" size="sm" icon="store">Visit the store</x-ds.button></p>
        </div>
        <x-ds.seller-trust :rungs="$directory->rungs($trust)" title="About this seller" />
    </div>
    <p class="ds-muted pdp-note">Checked means Emall's own sign-in with InfoDot recorded it. Claimed means the seller typed it and nobody has checked it. Measures come from orders on Emall, and show only once there are enough orders to mean something.</p>
</section>
