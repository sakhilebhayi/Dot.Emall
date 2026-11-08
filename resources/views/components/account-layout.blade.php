{{--
  <x-account-layout>: the CUSTOMER ACCOUNT. The storefront header and footer, with a navigation rail (desktop) and a bottom bar with a More sheet (phones; the scrolling sub-nav that repeated the bottom bar was removed, R-43 polish #10, D-183) for
  overview, orders, returns, wishlist, saved searches, addresses, alerts, profile (App\Support\Navigation::account()). Jetstream pages (profile, teams, API tokens) use it too.
  Props and slots are those of <x-store-layout> (title, description, heading, lead, actions, breadcrumbs, location, suggestions, testmode, banner, head, scripts, default; categories, robots='noindex').
--}}
@props(['categories' => null, 'robots' => 'noindex'])
@php
    $viewer = \App\Support\Navigation::viewer();
    $cats = $categories ?? \App\Support\Navigation::categories();
    $cartCount = \App\Support\Shell::cartCount();
    $items = \App\Support\Navigation::account();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'description' => $description ?? null, 'robots' => $robots])
    @isset($head){{ $head }}@endisset
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="ds-ctx-account has-bottom-bar">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-app">
        <div>
            @include('layouts.partials.store-header', ['categories' => $cats, 'location' => $location ?? null, 'suggestions' => $suggestions ?? null, 'testmode' => $testmode ?? null, 'viewer' => $viewer, 'cartCount' => $cartCount])
            <div class="ds-banners"><x-banner />
                @isset($banner){{ $banner }}@endisset
                @if (session('warning'))<x-ds.alert tone="cau" banner>{{ session('warning') }}</x-ds.alert>
@endif
            </div>
            <div class="ds-container ds-account">
                <aside class="ds-account__rail"><nav class="ds-rail__nav" aria-label="Your account">@include('layouts.partials.desk-rail', ['items' => $items])</nav></aside>
                <div style="min-width: 0;">
                    <main id="main" class="ds-main" tabindex="-1" style="padding-bottom: var(--s-6);">
                        @isset($breadcrumbs)<div style="padding-top: var(--s-4);">{{ $breadcrumbs }}</div>@endisset
                        @isset($heading)
                            <x-ds.page-header :title="html_entity_decode((string) $heading, ENT_QUOTES | ENT_HTML5)" :lead="isset($lead) ? html_entity_decode((string) $lead, ENT_QUOTES | ENT_HTML5) : null">
                                @isset($actions)<x-slot:actions>{{ $actions }}</x-slot:actions>
@endisset
                            </x-ds.page-header>
                        @endisset
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
        @include('layouts.partials.store-footer', ['viewer' => $viewer])
    </div>
    @include('layouts.partials.desk-bottom-bar', ['items' => $items, 'moreId' => 'account-menu-sheet'])
    <x-ds.dialog id="account-menu-sheet" title="Your account" :drawer="true" :sheet="false">
        <nav class="ds-drawer-nav" aria-label="Your account menu">@include('layouts.partials.desk-rail', ['items' => $items, 'plain' => true])<hr><a href="{{ url('/') }}">Back to the shop</a></nav>
    </x-ds.dialog>
    <x-ds.dialog id="menu-drawer" title="Menu" :drawer="true" :sheet="false">
        <nav class="ds-drawer-nav" aria-label="Menu">
            <a href="{{ emall_route('products.index') }}">All products</a>
            @foreach ($cats as $c)<details><summary>{{ $c['name'] }}<x-ds.icon name="chevron-down" :size="18" /></summary><div><a href="{{ $c['href'] }}">All in {{ $c['name'] }}</a>@foreach ($c['children'] as $k)<a href="{{ $k['href'] }}">{{ $k['name'] }}</a>@endforeach</div></details>@endforeach
            <hr><x-ds.theme-toggle variant="menu" />
        </nav>
    </x-ds.dialog>
    <x-ds.toasts />
    @isset($scripts){{ $scripts }}@endisset
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
