{{--
  <x-store-layout>: the PUBLIC STOREFRONT shell. Header (logo, search with autocomplete, account, cart with count), category navigation (mega menu on desktop, drawer on phones),
  utility row (delivery location chip, help, sell), footer with real links. Use it for the home page, search, categories, product, store, cart, checkout and help pages.
  Props:   categories=null (null reads App\Support\Navigation::categories(); pass a list of ['name','href','children'=>[['name','href']]] to override), robots=null,
           contained=true (wrap the page in the 80rem container; pass :contained="false" for full-bleed bands, then use <div class="ds-container"> inside),
           bottomBar=false (reserve room for a fixed bottom bar, for example the sticky add-to-cart bar on a product page; the page supplies the bar).
  Slots:   title (REQUIRED, unique per page: the <title>, "Title | Dot.Emall"), description (meta description), heading (the page's one <h1>, rendered as a page header; omit it when the page
           renders its own <h1>), lead (one sentence under the heading), actions (buttons right of the heading), breadcrumbs (a <x-ds.breadcrumbs>), location (the delivery-location chip, shown
           top left), suggestions (server-rendered search suggestions: <li role="option"> items), testmode (overrides the automatic test-mode badge), banner (a full-width <x-ds.alert banner>),
           head (extra <head> content), scripts (end of <body>), default (the page).
  Also provides: skip link, one <main id="main">, toast region for session flash messages, the notification bell for signed-in users, theme toggle, the phone menu drawer.
--}}
@props(['categories' => null, 'robots' => null, 'contained' => true, 'bottomBar' => false])
@php
    $viewer = \App\Support\Navigation::viewer();
    $cats = $categories ?? \App\Support\Navigation::categories();
    $cartCount = \App\Support\Shell::cartCount();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'description' => $description ?? null, 'robots' => $robots])
    @isset($head){{ $head }}@endisset
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="ds-ctx-store @if ($bottomBar) has-bottom-bar @endif">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-app">
        <div>
            @include('layouts.partials.store-header', ['categories' => $cats, 'location' => $location ?? null, 'suggestions' => $suggestions ?? null, 'testmode' => $testmode ?? null, 'viewer' => $viewer, 'cartCount' => $cartCount])
            <div class="ds-banners"><x-banner />
                @isset($banner){{ $banner }}@endisset
                @if (session('warning'))<x-ds.alert tone="cau" banner>{{ session('warning') }}</x-ds.alert>
@endif
            </div>
            <main id="main" class="ds-main" tabindex="-1">
                @if ($contained)<div class="ds-container">@endif
                    @isset($breadcrumbs)<div style="padding-top: var(--s-4);">{{ $breadcrumbs }}</div>@endisset
                    @isset($heading)
                        <x-ds.page-header :title="(string) $heading" :lead="isset($lead) ? (string) $lead : null">
                            @isset($actions)<x-slot:actions>{{ $actions }}</x-slot:actions>
@endisset
                        </x-ds.page-header>
                    @endisset
                    {{ $slot }}
                @if ($contained)</div>@endif
            </main>
        </div>
        @include('layouts.partials.store-footer', ['viewer' => $viewer])
    </div>

    <x-ds.dialog id="menu-drawer" title="Menu" :drawer="true" :sheet="false">
        <nav class="ds-drawer-nav" aria-label="Menu">
            <a href="{{ emall_route('products.index') }}">All products</a>
            @foreach ($cats as $c)
                <details>
                    <summary>{{ $c['name'] }}<x-ds.icon name="chevron-down" :size="18" /></summary>
                    <div><a href="{{ $c['href'] }}">All in {{ $c['name'] }}</a>@foreach ($c['children'] as $k)<a href="{{ $k['href'] }}">{{ $k['name'] }}</a>@endforeach</div>
                </details>
            @endforeach
            <a href="{{ emall_route('stores.index') }}" {!! emall_pending('stores.index') !!}>Stores</a>
            <hr>
            @if ($viewer['user'])
                <a href="{{ emall_route('account.home') !== '#' ? emall_route('account.home') : route('dashboard') }}"><span>Account</span></a>
                <a href="{{ emall_route('orders.index') }}">Orders</a>
                <a href="{{ emall_route('wishlist.index') }}" {!! emall_pending('wishlist.index') !!}>Wishlist</a>
            @else
                <a href="{{ route('login') }}"><span>Sign in with InfoDot</span><x-ds.icon name="arrow-right" :size="18" /></a>
                <p class="ds-drawer-note">Accounts come from InfoDot. Sign in to buy, sell and follow your orders.</p>
            @endif
            <a href="{{ emall_route('help.index') }}" {!! emall_pending('help.index') !!}>Help</a>
            <a href="{{ emall_route('sell.start') !== '#' ? emall_route('sell.start') : emall_route('seller.store.create') }}">Sell on Emall</a>
            <hr>
            <x-ds.theme-toggle variant="menu" />
        </nav>
    </x-ds.dialog>

    <x-ds.toasts />
    @isset($scripts){{ $scripts }}@endisset
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
