{{--
  <x-store-layout>: the PUBLIC STOREFRONT shell. Header (logo, search with autocomplete, account, cart with count), 
  one 56px shared header (Categories dropdown on desktop, drawer on phones), a thin strip under it for the delivery location chip and the test-mode badge, footer with real links. Use it for the home page, search, categories, product, store, cart, checkout and help pages.
  Props:   categories=null (null reads App\Support\Navigation::categories(); pass a list of ['name','href','children'=>[['name','href']]] to override), robots=null,
           contained=true (wrap the page in the 80rem container; pass :contained="false" for full-bleed bands, then use <div class="ds-container"> inside),
           bottomBar=false (reserve room for a fixed bottom bar, for example the sticky add-to-cart bar on a product page; the page supplies the bar).
  Slots:   title (REQUIRED, unique per page: the <title>, "Title | Dot.Emall"), description (meta description), heading (the page's one <h1>, rendered as a page header; omit it when the page
           renders its own <h1>), lead (one sentence under the heading), actions (buttons right of the heading), breadcrumbs (a <x-ds.breadcrumbs>), location (the delivery-location chip, shown
           top left), suggestions (server-rendered search suggestions: <a class="dsr-item" role="option" href=".."> rows inside the shared search pill's panel), testmode (overrides the automatic test-mode badge), banner (a full-width <x-ds.alert banner>),
           head (extra <head> content), scripts (end of <body>), default (the page).
  Also provides: skip link, one <main id="main">, toast region for session flash messages, the notification bell for signed-in users, and (R-52) the shared dot-ui header with its phone menu drawer (#dh-drawer, printed right after the header).
--}}
@props(['categories' => null, 'robots' => null, 'contained' => true, 'bottomBar' => false])
@php
    $viewer = \App\Support\Navigation::viewer();
    $cats = $categories ?? \App\Support\Navigation::categories();
    $cartCount = \App\Support\Shell::cartCount();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="emall-theme" data-dh-theme-default="system">
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
                        <x-ds.page-header :title="html_entity_decode((string) $heading, ENT_QUOTES | ENT_HTML5)" :lead="isset($lead) ? html_entity_decode((string) $lead, ENT_QUOTES | ENT_HTML5) : null">
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

    <x-ds.toasts />
    @isset($scripts){{ $scripts }}@endisset
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
