{{--
  <x-desk-shell>: the shared frame of the SELLER DESK and the OPERATOR area (and nothing else should use it directly: use <x-seller-layout> or <x-operator-layout>).
  The shared dot-ui header (R-52: logo, the desk's label, a real search of this desk, platform switcher, notifications, account), a navigation rail from 64rem up, a bottom bar whose "More" opens the header's drawer on phones.
  Props: context ('seller' | 'operator'), items (a App\Support\Navigation list), label (e.g. "Seller desk"), sub=null (e.g. the store name), strip='awning' | 'ink', robots='noindex', contained=true.
  Slots: title, description, heading, lead, actions, breadcrumbs, banner, head, scripts, default.
--}}
@props(['context', 'items', 'label', 'sub' => null, 'strip' => 'awning', 'robots' => 'noindex', 'contained' => true])
@php $viewer = \App\Support\Navigation::viewer(); $user = $viewer['user']; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="emall-theme" data-dh-theme-default="system">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'description' => $description ?? null, 'robots' => $robots, 'dotUiSwitcher' => true])
    @isset($head){{ $head }}@endisset
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="ds-ctx-{{ $context }} has-bottom-bar">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-strip ds-strip--{{ $strip }}" aria-hidden="true"></div>
    <div class="ds-desk">
        @php
            $isOperator = $context === 'operator';
            $searchRoute = $isOperator ? 'operator.find' : 'seller.find';
            $listRoute = $isOperator ? 'operator.products.index' : 'seller.products.index';
            $account = \App\Support\HeaderNav::account($viewer, desk: true);
        @endphp
        {{-- R-52: the shared dot-ui header (56px, ONE bar) with a real search of THIS desk. The label ("Seller desk", "Operator area") is the always-visible status; the rail is the desktop navigation, so the bar has no primary items. --}}
        <x-dot.header class="ds-deskbar" brand-href="{{ url('/') }}" brand-label="Dot.Emall, home" :account="$account" menu-label="Open menu" drawer-title="{{ $label }}" search-label="{{ $isOperator ? 'Search the operator area' : 'Search your store' }}">
            <x-slot:brand>@include('layouts.partials.brand', ['word' => false])</x-slot:brand>
            <x-slot:status><span class="dh-status__text ds-deskbar__context"><strong>{{ $label }}</strong>@if ($sub)<span class="ds-deskbar__sub">{{ $sub }}</span>@endif</span></x-slot:status>
            <x-slot:search>
                @if ($user && \App\Support\Shell::routeExists($searchRoute))
                    <x-dot.search name="q" id="desk-search-input" :action="route($listRoute)" :hidden-fields="$isOperator ? ['status' => 'all'] : []" :value="is_string(request('q')) ? request('q') : ''"
                        :placeholder="$isOperator ? 'Search listings, stores, orders and people' : 'Search your products and orders'" :label="$isOperator ? 'Search the operator area' : 'Search your store'"
                        results-nav enter-first escape-clears loading-text="Looking..." :root-attributes="['id' => 'desk-search', 'data-desk-search' => route($searchRoute)]">
                        <x-slot:results><p class="dsr-foot">{{ $isOperator ? 'Find a listing, store, order number or person.' : 'Find your own products, orders and returns.' }}</p></x-slot:results>
                    </x-dot.search>
                @endif
            </x-slot:search>
            <x-slot:tools>
                @if ($user)
                    @include('layouts.partials.dot-switcher', ['user' => $user])
                    <livewire:notification-bell />
                @endif
            </x-slot:tools>
            <x-slot:drawer>
                <nav class="dh-dnav" aria-label="{{ $label }} menu">
                    <ul>
                        @foreach ($items as $item)
                            @php($r = \App\Support\Shell::first(array_merge([$item['route']], $item['alt'] ?? [])))
                            <li><a class="dh-dnav__link" href="{{ $r['url'] }}" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif><span class="dh-menu__label">{{ $item['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </nav>
            </x-slot:drawer>
        </x-dot.header>
        @if (($testLabel = \App\Support\Shell::testModeLabel()) !== null)<div class="ds-util"><div class="ds-container ds-util__row"><div class="ds-util__test"><x-ds.badge tone="cau" icon="alert-triangle">{{ $testLabel }}</x-ds.badge></div></div></div>
@endif
        <div class="ds-desk__body">
            <aside class="ds-rail">
                <nav class="ds-rail__nav" aria-label="{{ $label }}">@include('layouts.partials.desk-rail', ['items' => $items])</nav>
                <div class="ds-rail__foot ds-rail__nav">
                    <a href="{{ url('/') }}"><x-ds.icon name="arrow-left" :size="20" /><span>Back to the shop</span></a>
                </div>
            </aside>
            <div class="ds-desk__main">
                <div class="ds-banners"><x-banner />
                    @isset($banner){{ $banner }}@endisset
                    @if (session('warning'))<x-ds.alert tone="cau" banner>{{ session('warning') }}</x-ds.alert>
@endif
                </div>
                <main id="main" class="ds-main" tabindex="-1">
                    @if ($contained)<div class="ds-container" style="max-width: 72rem; margin-inline: 0;">@endif
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
        </div>
    </div>
    @include('layouts.partials.desk-bottom-bar', ['items' => $items])
    <x-ds.toasts />
    @isset($scripts){{ $scripts }}@endisset
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
