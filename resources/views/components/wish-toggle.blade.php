{{--
  <x-wish-toggle>: the wishlist heart. Works without script (a form POST to wishlist.toggle that redirects back) and is enhanced by resources/js/product.js
  (it saves in place, flips aria-pressed and announces the result). A guest sees a link to sign in, with one honest sentence in its title.
  Props: product (required: a Product or its id), name=null (the product's name for the accessible label; read from a Product), variantId=null (save a chosen variant too),
         saved=null (null reads the signed-in person's list once per request through App\Support\WishState; pass true/false when you already know),
         style='icon' | 'button' (icon: a 44px heart for a card; button: a quiet labelled button for the product page).
  Use it in the product card's wishlist slot:  <x-ds.product-card ...><x-slot:wishlist><x-wish-toggle :product="$p" /></x-slot:wishlist></x-ds.product-card>
--}}
@props(['product', 'name' => null, 'variantId' => null, 'saved' => null, 'style' => 'icon'])
@php
    $id = $product instanceof \App\Models\Product ? $product->id : (int) $product;
    $name = $name ?? ($product instanceof \App\Models\Product ? $product->name : 'this product');
    $user = auth()->user();
    $saved = $saved ?? ($user ? \App\Support\WishState::has($user, $id) : false);
    $on = 'Remove '.$name.' from your wishlist';
    $off = 'Save '.$name.' to your wishlist';
@endphp
@if ($user === null)
    @if ($style === 'button')
        <a href="{{ route('login') }}" class="ds-btn ds-btn--quiet ds-btn--md" title="Accounts come from InfoDot. Sign in to save this to a wishlist."><x-ds.icon name="heart" :size="18" />Sign in to save</a>
    @else
        <a href="{{ route('login') }}" class="ds-icon-btn" aria-label="Sign in to save {{ $name }} to a wishlist" title="Accounts come from InfoDot. Sign in to save this to a wishlist."><x-ds.icon name="heart" :size="22" /></a>
    @endif
@else
    <form method="POST" action="{{ route('wishlist.toggle', $id) }}" class="wish" data-wish-toggle data-label-on="{{ $on }}" data-label-off="{{ $off }}" data-text-on="Saved to wishlist" data-text-off="Save to wishlist">
        @csrf
        @if ($variantId)<input type="hidden" name="variant_id" value="{{ $variantId }}">@endif
        @if ($style === 'button')
            <button type="submit" class="ds-btn ds-btn--quiet ds-btn--md" aria-pressed="{{ $saved ? 'true' : 'false' }}" data-wish-button><x-ds.icon name="heart" :size="18" /><span data-wish-text>{{ $saved ? 'Saved to wishlist' : 'Save to wishlist' }}</span></button>
        @else
            <button type="submit" class="ds-icon-btn" aria-pressed="{{ $saved ? 'true' : 'false' }}" aria-label="{{ $saved ? $on : $off }}" title="{{ $saved ? $on : $off }}" data-wish-button><x-ds.icon name="heart" :size="22" /></button>
        @endif
    </form>
@endif
