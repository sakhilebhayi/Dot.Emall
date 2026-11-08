{{--
  ds.product-gallery: the product's photographs. Thumbnails, previous/next buttons, arrow keys, swipe, and zoom (click or tap the photo, or the zoom button; moving the mouse pans).
  Props: images (required: list of ['src' =>, 'alt' => 'Describe the photo', 'width' => null, 'height' => null]), ratio='1 / 1' (the stage's aspect ratio, set so nothing jumps while loading),
         layout='below' | 'side' (thumbnails below, or in a column on wide screens), zoom=true, label='Product photos'.
  Every photo of a product loads eagerly (a gallery holds at most six, and the thumbnails are the first thing a shopper reaches for); the stage and each thumbnail reserve their size and show a quiet skeleton until the picture is there.
  With no photo the stage says so: "Seller has not added a photo". With one photo there are no thumbnails and no arrows. Without script the first photo shows and the thumbnails are plain buttons that do nothing.
--}}
@props(['images', 'ratio' => '1 / 1', 'layout' => 'below', 'zoom' => true, 'label' => 'Product photos'])
@php($n = count($images))
<div {{ $attributes->class(['ds-gallery', 'ds-gallery--side' => $layout === 'side' && $n > 1]) }} role="group" aria-roledescription="carousel" aria-label="{{ $label }}">
    <div class="ds-gallery__stage" style="--ratio: {{ $ratio }}" @if ($zoom) data-zoomable @endif>
        @forelse ($images as $i => $img)
            <figure class="ds-gallery__slide {{ $i === 0 ? 'is-active' : '' }}" role="group" aria-roledescription="slide" aria-label="Photo {{ $i + 1 }} of {{ $n }}">
                <img src="{{ $img['src'] }}" alt="{{ $img['alt'] ?? '' }}" loading="eager" decoding="{{ $i === 0 ? 'sync' : 'async' }}" @if ($i === 0) fetchpriority="high" @endif width="{{ $img['width'] ?? 800 }}" height="{{ $img['height'] ?? 800 }}" draggable="false">
            </figure>
        @empty
            <figure class="ds-gallery__slide ds-gallery__slide--none is-active"><x-ds.icon name="image" :size="40" /><figcaption>Seller has not added a photo</figcaption><small>Read the description, or ask the seller, before you buy.</small></figure>
        @endforelse
        @if ($n > 1)
            <button type="button" class="ds-icon-btn ds-gallery__nav ds-gallery__nav--prev" data-gallery="prev" aria-label="Previous photo"><x-ds.icon name="chevron-left" /></button>
            <button type="button" class="ds-icon-btn ds-gallery__nav ds-gallery__nav--next" data-gallery="next" aria-label="Next photo"><x-ds.icon name="chevron-right" /></button>
            <span class="ds-gallery__count" aria-live="polite"><span class="ds-gallery__count-now">1</span> / {{ $n }}</span>
        @endif
        @if ($zoom && $n > 0)<button type="button" class="ds-icon-btn ds-gallery__zoom" data-gallery="zoom" aria-label="Zoom photo"><x-ds.icon name="zoom-in" /></button>@endif
    </div>
    @if ($n > 1)
        <ul class="ds-gallery__thumbs" aria-label="Choose a photo">
            @foreach ($images as $i => $img)
                <li><button type="button" class="ds-gallery__thumb" aria-label="Show photo {{ $i + 1 }} of {{ $n }}" @if ($i === 0) aria-current="true" @endif><img src="{{ $img['thumb'] ?? $img['src'] }}" alt="" loading="eager" decoding="async" width="68" height="68"></button></li>
            @endforeach
        </ul>
    @endif
</div>
