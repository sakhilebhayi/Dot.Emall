{{--
  The public storefront header. Included by x-store-layout and x-account-layout. Variables: $categories (list, see App\Support\Navigation::categories()), $location (slot or null), $suggestions (slot or null),
  $testmode (slot or null), $viewer (App\Support\Navigation::viewer()), $cartCount, $switcher (true only in the signed-in account: the platform switcher is drawn there and never on the public shop, so a catalogue page never asks InfoDot).
--}}
@php
    $user = $viewer['user'];
    $searchUrl = emall_route('search') !== '#' ? emall_route('search') : emall_route('products.index');
    $cartHref = emall_route('cart.index');
@endphp
<div class="ds-awning" aria-hidden="true"></div>
<div class="ds-util">
    <div class="ds-container ds-util__row">
        <div class="ds-util__loc">{{ $location ?? '' }}</div>
        <div class="ds-util__test">@if (isset($testmode) && trim((string) $testmode) !== ''){{ $testmode }}@elseif ($label = \App\Support\Shell::testModeLabel())<x-ds.badge tone="cau" icon="alert-triangle">{{ $label }}</x-ds.badge>
@endif</div>
        <div class="ds-util__links">
            <a href="{{ emall_route('help.index') }}" {!! emall_pending('help.index') !!}>Help</a>
            <a href="{{ emall_route('sell.start') !== '#' ? emall_route('sell.start') : emall_route('seller.store.create') }}">Sell on Emall</a>
        </div>
    </div>
</div>
<header class="ds-header">
    <div class="ds-container ds-header__bar">
        <button type="button" class="ds-icon-btn ds-header__menu" data-dialog-open="menu-drawer" aria-label="Open menu"><x-ds.icon name="menu" :size="24" /></button>
        <x-ds.logo class="ds-header__brand" :href="url('/')" />
        <div class="ds-header__search">
            {{-- The shared Dot UI pill (R-51), the same control as InfoDot's. Only its look is shared: the form still GETs the shop's own search with ?q=, and the
                 suggestions still come from the shop's own search.suggest endpoint (resources/js/app.js fills [data-suggest-list]). The panel stays hidden while it is empty. --}}
            <x-dot.search name="q" id="site-search-input" :action="$searchUrl" :value="is_string(request('q')) ? request('q') : ''" placeholder="Search products, brands and stores" label="Search products"
                :root-attributes="array_filter(['id' => 'site-search', 'data-suggest-endpoint' => \App\Support\Shell::routeExists('search.suggest') ? route('search.suggest') : null])">
                <x-slot:results><div class="dsr-quick ds-suggest" data-suggest-list>@isset($suggestions){{ $suggestions }}@endisset</div></x-slot:results>
            </x-dot.search>
        </div>
        <div class="ds-header__actions">
            <button type="button" class="ds-icon-btn ds-header__searchbtn" data-search-reveal aria-label="Search"><x-ds.icon name="search" :size="22" /></button>
            <x-ds.theme-toggle class="ds-header__hide-sm" />
            @if ($user)
                @if ($switcher ?? false)@include('layouts.partials.dot-switcher', ['user' => $user])@endif
                <x-ds.dropdown :label="\Illuminate\Support\Str::of($user->name)->before(' ')" id="account-menu" icon="user" bare>
                    <p class="ds-dropdown__note">Signed in as <strong>{{ $user->name }}</strong></p>
                    <hr class="ds-dropdown__sep">
                    <x-ds.dropdown-item :href="emall_route('account.home') !== '#' ? emall_route('account.home') : route('dashboard')" icon="home">Account overview</x-ds.dropdown-item>
                    <x-ds.dropdown-item :href="emall_route('orders.index')" icon="receipt">Orders</x-ds.dropdown-item>
                    <x-ds.dropdown-item :href="emall_route('wishlist.index')" icon="heart" :data-pending-route="emall_pending_name('wishlist.index')">Wishlist</x-ds.dropdown-item>
                    @if ($viewer['hasStore'])
                        <x-ds.dropdown-item :href="emall_route('seller.dashboard') !== '#' ? emall_route('seller.dashboard') : emall_route('seller.products.index')" icon="store">Seller desk</x-ds.dropdown-item>
                    @else
                        <x-ds.dropdown-item :href="emall_route('seller.store.create')" icon="store">Sell on Emall</x-ds.dropdown-item>
                    @endif
                    @if ($viewer['isOperator'])<x-ds.dropdown-item :href="emall_route('operator.products.index')" icon="flag">Operator area</x-ds.dropdown-item>
@endif
                    <x-ds.dropdown-item :href="emall_route('profile.show')" icon="lock">Profile and security</x-ds.dropdown-item>
                    <hr class="ds-dropdown__sep">
                    <x-ds.theme-toggle variant="menu" />
                    <form method="POST" action="{{ route('logout') }}">@csrf<x-ds.dropdown-item type="submit" icon="logout">Sign out</x-ds.dropdown-item></form>
                </x-ds.dropdown>
                <livewire:notification-bell />
            @else
                <a href="{{ route('login') }}" class="ds-header__link"><x-ds.icon name="user" :size="22" /><span>Sign in</span></a>
            @endif
            <a href="{{ $cartHref }}" class="ds-header__link ds-cart-link" {!! emall_pending('cart.index') !!}>
                <x-ds.icon name="cart" :size="24" />
                <span class="ds-label-text">Cart</span>
                @if ($cartCount > 0)<span class="ds-count" aria-hidden="true">{{ $cartCount > 99 ? '99+' : $cartCount }}</span><span class="ds-visually-hidden">{{ $cartCount }} {{ \Illuminate\Support\Str::plural('item', $cartCount) }} in your cart</span>@else<span class="ds-visually-hidden">, empty</span>@endif
            </a>
        </div>
    </div>
    <nav class="ds-catnav" aria-label="Shop by category">
        <div class="ds-container ds-catnav__row">
            @if (count($categories))
                <button type="button" class="ds-catnav__btn" data-disclosure aria-expanded="false" aria-controls="mega-menu"><x-ds.icon name="menu" :size="18" />All categories<x-ds.icon name="chevron-down" :size="16" /></button>
                @foreach (array_slice($categories, 0, 6) as $c)<a href="{{ $c['href'] }}">{{ $c['name'] }}</a>@endforeach
                <div class="ds-mega" id="mega-menu" data-disclosure-panel hidden>
                    @foreach ($categories as $c)
                        <div><h2><a href="{{ $c['href'] }}">{{ $c['name'] }}</a></h2>@if (count($c['children']))<ul>@foreach ($c['children'] as $k)<li><a href="{{ $k['href'] }}">{{ $k['name'] }}</a></li>@endforeach</ul>@endif</div>
                    @endforeach
                </div>
            @else
                <a href="{{ emall_route('products.index') }}" @if (request()->routeIs('products.*')) aria-current="page" @endif>All products</a>
            @endif
            <span class="ds-catnav__spacer"></span>
            <a href="{{ emall_route('stores.index') }}" {!! emall_pending('stores.index') !!}>Stores</a>
        </div>
    </nav>
</header>
