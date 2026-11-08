{{--
  ds.cart-line: one line in the cart or the checkout summary. Photograph, title, the chosen option, unit price, controls and the line total.
  Props: title (required), href=null, image=null, store=null, option=null (e.g. "Size M, Blue"), unitCents=null, quantity (required, int), lineCents (required), note=null (e.g. a real stock warning), readonly=false.
  Slot: the controls (a ds.quantity-stepper and a remove form). Omit the slot for a read-only summary line.
--}}
@props(['title', 'href' => null, 'image' => null, 'store' => null, 'option' => null, 'unitCents' => null, 'quantity', 'lineCents', 'note' => null])
<div {{ $attributes->class('ds-cartline') }}>
    <span class="ds-cartline__media">@if ($image)<img src="{{ $image }}" alt="" loading="eager" decoding="async" width="104" height="104">@else<span class="ds-cartline__noimg">No photo</span>@endif</span>
    <div class="ds-cartline__info">
        @if ($store)<span class="ds-cartline__meta">{{ $store }}</span>@endif
        <span class="ds-cartline__title">@if ($href)<a href="{{ $href }}">{{ $title }}</a>@else{{ $title }}@endif</span>
        @if ($option)<span class="ds-cartline__meta">{{ $option }}</span>@endif
        @if ($unitCents !== null)<span class="ds-cartline__meta">{{ \App\Support\Shop::money((int) $unitCents) }} each, quantity {{ $quantity }}</span>@endif
        @if ($note)<span class="ds-cartline__meta" style="color: var(--cau); font-weight: 600;">{{ $note }}</span>@endif
    </div>
    <div class="ds-cartline__controls">
        {{ $slot }}
        <span class="ds-cartline__total"><x-ds.price :cents="$lineCents" size="md" /></span>
    </div>
</div>
