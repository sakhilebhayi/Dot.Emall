{{--
  <x-desk-shell>: the shared frame of the SELLER DESK and the OPERATOR area (and nothing else should use it directly: use <x-seller-layout> or <x-operator-layout>).
  A top bar (logo, context name, storefront link, notifications, theme, account), a navigation rail from 64rem up, a bottom bar and a "More" sheet on phones.
  Props: context ('seller' | 'operator'), items (a App\Support\Navigation list), label (e.g. "Seller desk"), sub=null (e.g. the store name), strip='awning' | 'ink', robots='noindex', contained=true.
  Slots: title, description, heading, lead, actions, breadcrumbs, banner, head, scripts, default.
--}}
@props(['context', 'items', 'label', 'sub' => null, 'strip' => 'awning', 'robots' => 'noindex', 'contained' => true])
@php $viewer = \App\Support\Navigation::viewer(); $user = $viewer['user']; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'description' => $description ?? null, 'robots' => $robots])
    @isset($head){{ $head }}@endisset
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="ds-ctx-{{ $context }} has-bottom-bar">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-strip ds-strip--{{ $strip }}" aria-hidden="true"></div>
    <div class="ds-desk">
        <header class="ds-deskbar">
            <div class="ds-deskbar__row">
                <button type="button" class="ds-icon-btn ds-deskbar__menu" data-dialog-open="desk-menu" aria-label="Open menu"><x-ds.icon name="menu" :size="24" /></button>
                <x-ds.logo :href="url('/')" :word="false" />
                <div class="ds-deskbar__context"><strong>{{ $label }}</strong>@if ($sub)<span>{{ $sub }}</span>@endif</div>
                <span class="ds-deskbar__space"></span>
                @if (($testLabel = \App\Support\Shell::testModeLabel()) !== null)<x-ds.badge tone="cau" icon="alert-triangle" class="ds-header__hide-sm">{{ $testLabel }}</x-ds.badge>
@endif
                <a href="{{ url('/') }}" class="ds-header__link ds-header__hide-sm"><x-ds.icon name="store" :size="20" /><span>Storefront</span></a>
                <x-ds.theme-toggle />
                @if ($user)
                    <livewire:notification-bell />
                    <x-ds.dropdown :label="\Illuminate\Support\Str::of($user->name)->before(' ')" id="desk-account" icon="user" bare>
                        <p class="ds-dropdown__note">Signed in as <strong>{{ $user->name }}</strong></p>
                        <hr class="ds-dropdown__sep">
                        <x-ds.dropdown-item :href="url('/')" icon="store">Back to the shop</x-ds.dropdown-item>
                        <x-ds.dropdown-item :href="emall_route('profile.show')" icon="lock">Profile and security</x-ds.dropdown-item>
                        @include('layouts.partials.team-menu', ['user' => $user])
                        <hr class="ds-dropdown__sep">
                        <form method="POST" action="{{ route('logout') }}">@csrf<x-ds.dropdown-item type="submit" icon="logout">Sign out</x-ds.dropdown-item></form>
                    </x-ds.dropdown>
                @endif
            </div>
        </header>
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
                            <x-ds.page-header :title="(string) $heading" :lead="isset($lead) ? (string) $lead : null">
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
    @include('layouts.partials.desk-bottom-bar', ['items' => $items, 'moreId' => 'desk-menu'])
    <x-ds.dialog id="desk-menu" :title="$label" :drawer="true" :sheet="false">
        <nav class="ds-drawer-nav" aria-label="{{ $label }} menu">
            @include('layouts.partials.desk-rail', ['items' => $items, 'plain' => true])
            <hr>
            <a href="{{ url('/') }}">Back to the shop</a>
            <x-ds.theme-toggle variant="menu" />
        </nav>
    </x-ds.dialog>
    <x-ds.toasts />
    @isset($scripts){{ $scripts }}@endisset
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
