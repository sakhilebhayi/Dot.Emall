{{--
  ds.product-card: a product as a photograph first, then the facts. A product with no photograph shows a tile that says so (the name large, "Seller has not added a photo"), never a picture of something else. No box, no shadow; the title link stretches over the card (one tab stop).
  Props: href (required), title (required), image=null (URL), imageAlt='' (the title already names it), store=null (store or brand name), priceCents=null / amount=null (decimal string),
         previousCents=null (real 30-day low, see ds.price), unit=null, ratingValue=0, ratingCount=0 (no stars at zero reviews), stock=null (REAL remaining quantity; at 5 or fewer it prints "Only N left",
         at 0 the card dims and says "Out of stock"), variant='grid' | 'row' (row is the phone list), ratio='4 / 5', eager=false (true for the first row above the fold), state=null (product state such as
         'pending_review' or 'unlisted' for the seller's own previews).
  Slot wishlist: the wishlist toggle (a ds.icon-button with aria-pressed), placed top right over the photo. The slot's control must not be inside the title link.
--}}
@props(['href', 'title', 'image' => null, 'imageAlt' => '', 'store' => null, 'priceCents' => null, 'amount' => null, 'previousCents' => null, 'unit' => null, 'ratingValue' => 0, 'ratingCount' => 0, 'stock' => null, 'variant' => 'grid', 'ratio' => '4 / 5', 'eager' => false, 'state' => null])
@php
    // The photo's reserved box: width and height follow the ratio so the page does not jump when the picture arrives.
    [$rw, $rh] = array_pad(array_map('floatval', preg_split('/\s*\/\s*/', $ratio) ?: []), 2, 1.0);
    $imgW = 480;
    $imgH = $rw > 0 ? (int) round($imgW * $rh / $rw) : 480;
    $stockState = \App\Support\Status::stockState($stock);
    $out = $stockState === 'out_of_stock' && $stock !== null;
@endphp
<article {{ $attributes->class(['ds-pcard', 'ds-pcard--row' => $variant === 'row', 'is-out' => $out]) }}>
    <div class="ds-pcard__media" style="--ratio: {{ $ratio }}">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $imageAlt }}" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async" width="{{ $imgW }}" height="{{ $imgH }}" @if ($eager) fetchpriority="high" @endif>
        @else
            {{-- No picture: say so, and lead with the product's name (the body below carries the real link and the price). Never a stand-in picture of something else. --}}
            <span class="ds-pcard__noimg"><span class="ds-pcard__noimg-name" aria-hidden="true">{{ $title }}</span><span class="ds-pcard__noimg-note"><x-ds.icon name="image" :size="16" />Seller has not added a photo</span></span>
        @endif
        @if ($out)<x-ds.status kind="product" state="out_of_stock" class="ds-pcard__tag" />@elseif ($state)<x-ds.status kind="product" :state="$state" class="ds-pcard__tag" />@endif
        @isset($wishlist)<div class="ds-pcard__wish">{{ $wishlist }}</div>@endisset
    </div>
    <div class="ds-pcard__body">
        @if ($store)<span class="ds-pcard__store">{{ $store }}</span>@endif
        <h3 class="ds-pcard__title"><a href="{{ $href }}">{{ $title }}</a></h3>
        <x-ds.rating :value="$ratingValue" :count="$ratingCount" />
        @if ($priceCents !== null || $amount !== null)
            <x-ds.price class="ds-pcard__price" :cents="$priceCents" :amount="$amount" size="md" :previous-cents="$previousCents" :unit="$unit" />
        @endif
        @if ($stockState === 'low_stock' && $stock !== null)<span class="ds-pcard__stock ds-pcard__stock--cau"><x-ds.icon name="alert-triangle" :size="14" />Only {{ $stock }} left</span>@endif
    </div>
</article>
