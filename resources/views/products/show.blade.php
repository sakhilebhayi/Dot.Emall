{{--
  The product page (R-43 W4). Data: App\Http\Controllers\ProductPageController, App\Services\Storefront\{ProductPage, ProductReviews}.
  Layout: the photograph and the buy box side by side from 64rem (the box sticks while the page scrolls), stacked on a phone with a sticky bar.
  Only the buy box changes when a choice changes (pdp/partials/buy); the head (title, store, rating) stays put.
--}}
@php
    $variantByImage = $choices->variants->filter(fn ($v) => $v->image_id)->groupBy('image_id');
    $gallery = $images->values()->map(function ($i, $n) use ($product, $variantByImage) {
        $label = $variantByImage->get($i->id)?->first()?->label();

        return ['src' => $i->url(), 'alt' => $product->name.($label ? ', '.$label : '').($n > 0 ? ', photo '.($n + 1) : ''), 'width' => 800, 'height' => 800];
    })->all();
    $reviewCount = (int) $reviewData['summary']['count'];
    $questionCount = $questions->where('status', \App\Models\ProductQuestion::PUBLISHED)->count();
    $description = \Illuminate\Support\Str::limit(trim(strip_tags((string) ($product->description ?: $product->name.' from '.$store->name))), 155);
    $firstImage = $images->first()?->url();
    $bundle = null;
    foreach ($sets as $set) {
        if ($set->key === 'bought_together') { $bundle = app(\App\Services\Storefront\ProductPage::class)->bundle($set, ['priceCents' => $priceCents, 'priceFrom' => $priceFrom]); }
    }
@endphp
<x-store-layout :bottom-bar="$canBuy" :robots="$preview ? 'noindex' : null">
    <x-slot:title>{{ $product->name }}</x-slot:title>
    <x-slot:description>{{ $description }}</x-slot:description>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="$crumbs" /></x-slot:breadcrumbs>
    <x-slot:head>
        @if (! $preview)
            <link rel="canonical" href="{{ route('products.show', $product) }}">
            <meta property="og:type" content="product">
            <meta property="og:title" content="{{ $product->name }}">
            <meta property="og:description" content="{{ $description }}">
            <meta property="og:url" content="{{ route('products.show', $product) }}">
            @if ($firstImage)<meta property="og:image" content="{{ $firstImage }}">@endif
            @if ($jsonLd)<script type="application/ld+json" @if (csp_nonce()) nonce="{{ csp_nonce() }}" @endif>{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>@endif
        @endif
    </x-slot:head>

    @if ($preview)
        <x-ds.alert tone="cau" class="pdp-preview" title="Only you can see this page">
            <x-ds.status kind="product" :state="$preview" /> Shoppers get a "not found" page until the listing is live.
        </x-ds.alert>
    @endif

    <div class="ds-pdp pdp">
        <div class="pdp__media">
            <x-ds.product-gallery :images="$gallery" layout="side" id="pdp-gallery" />
        </div>

        <div class="ds-pdp__buy pdp__buy">
            <div class="ds-pdp__head pdp__head">
                <p class="ds-pdp__store">
                    @if ($brand)<span>{{ $brand }}</span> <span class="ds-muted">sold by</span> @else <span class="ds-muted">Sold by</span> @endif
                    <a href="{{ route('stores.show', $store) }}">{{ $store->name }}</a>
                </p>
                <h1>{{ $product->name }}</h1>
                @if ($reviewCount > 0)<x-ds.rating :value="$product->rating_average" :count="$reviewCount" href="#reviews" />@endif
            </div>

            @include('pdp.partials.buy')

            <details class="pdp-acc" id="returns">
                <summary>Returns and warranty<x-ds.icon name="chevron-down" :size="18" /></summary>
                <div class="pdp-acc__body">
                    <p><strong>Returns.</strong> You can ask to return an item within {{ $returnDays }} days of it being delivered or collected. If it is faulty or not as described, {{ $store->name }} pays for the return. If you changed your mind, you pay. The seller answers your request, and you can escalate to the marketplace if they decline.</p>
                    @if ($store->return_policy)
                        <p><strong>{{ $store->name }}'s return policy</strong></p>
                        <p class="pdp-policy">{{ $store->return_policy }}</p>
                    @endif
                    <p><strong>Warranty.</strong> Emall does not hold warranty terms for sellers. If {{ $store->name }} offers one it is in the policy above, or ask the store before you buy.</p>
                    <p class="ds-muted pdp-note">The {{ $returnDays }} days follow South Africa's e-commerce cooling-off period, applied as our standard. It is general information, not legal advice.</p>
                </div>
            </details>
        </div>
    </div>

    <nav class="pdp-jump" aria-label="On this page">
        <a href="#details">Details</a>
        <a href="#seller">Seller</a>
        <a href="#reviews">Reviews{{ $reviewCount > 0 ? ' ('.$reviewCount.')' : '' }}</a>
        <a href="#questions">Questions{{ $questionCount > 0 ? ' ('.$questionCount.')' : '' }}</a>
    </nav>

    <section class="pdp-section pdp-details" id="details" aria-labelledby="details-h">
        <div class="pdp-section__head"><h2 id="details-h">Details</h2></div>
        <div class="pdp-details__grid">
            <div>
                <h3 class="pdp-sub">About this product</h3>
                @if ($product->description)
                    <p class="ds-prose pdp-desc">{{ $product->description }}</p>
                @else
                    <p class="ds-muted">The seller has not written a description.</p>
                @endif
                <p class="ds-muted pdp-note">Written by {{ $store->name }}. Emall does not check what sellers write.</p>
            </div>
            <div>
                <h3 class="pdp-sub">Specifications</h3>
                @if (count($specs) > 0 || $brand || $product->sku)
                    <x-ds.table caption="Specifications" :stack="false">
                        <x-slot:head><tr><th scope="col">Feature</th><th scope="col">Value</th></tr></x-slot:head>
                        @if ($brand)<tr><th scope="row">Brand</th><td>{{ $brand }}</td></tr>@endif
                        @foreach ($specs as $row)<tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>@endforeach
                        @if ($variant?->sku ?: $product->sku)<tr><th scope="row">SKU</th><td><span class="ds-code">{{ $variant?->sku ?: $product->sku }}</span></td></tr>@endif
                    </x-ds.table>
                @else
                    <p class="ds-muted">The seller has not listed specifications.</p>
                @endif
            </div>
        </div>
    </section>

    @include('pdp.partials.seller')

    @foreach ($sets as $set)
        @include('pdp.partials.rail', ['set' => $set, 'id' => 'rec-'.$set->key, 'bundle' => $set->key === 'bought_together' ? $bundle : null])
    @endforeach

    @include('pdp.partials.reviews')

    @include('pdp.partials.questions')

    @if ($recent)
        @include('pdp.partials.rail', ['set' => $recent, 'id' => 'recently-viewed', 'bundle' => null])
    @endif

    @if ($isLive && ! $own)
        <section class="pdp-section pdp-report" id="report-listing" aria-label="Report this listing">
            @auth
                <x-report-form :action="route('products.report', $product)" :bag="'reportproduct'.$product->id" label="Report this listing" what="this listing" />
            @else
                <p class="ds-muted"><x-ds.icon name="flag" :size="16" /> <a href="{{ route('login') }}">Sign in with InfoDot</a> to report this listing.</p>
            @endauth
        </section>
    @endif

    <x-ds.dialog id="lightbox" title="Review photo" :sheet="false" class="pdp-lightbox">
        <figure class="pdp-lightbox__fig">
            <img src="" alt="" data-lightbox-img width="800" height="800">
            <figcaption data-lightbox-caption class="ds-muted"></figcaption>
        </figure>
        <x-slot:footer>
            <x-ds.button variant="quiet" data-lightbox-nav="-1" icon="chevron-left">Previous</x-ds.button>
            <x-ds.button variant="quiet" data-lightbox-nav="1" icon-right="chevron-right">Next</x-ds.button>
        </x-slot:footer>
    </x-ds.dialog>
</x-store-layout>
