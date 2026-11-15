{{--
  The public storefront header, included by x-store-layout and x-account-layout. R-52: ONE 56px bar of the shared dot-ui header (it was an awning, a utility row, a bar and a category row, 113 to 118px).
  Variables: $categories (App\Support\Navigation::categories()), $location (slot or null), $suggestions (slot or null), $testmode (slot or null), $viewer (App\Support\Navigation::viewer()), $cartCount,
  $switcher (true only in the signed-in account: the platform switcher is drawn there and never on the public shop, so a catalogue page never asks InfoDot), $accountItems (the account navigation, for the phone drawer).
  The decorative awning hangs above the bar (it scrolls away; only the bar is sticky). The delivery-location chip and the test-mode badge sit in a thin strip UNDER the bar, only when there is something to say.
  Where things went: Help and Sell on Emall are bar items; the category row is the "Categories" dropdown (the phone drawer keeps every sub-category); the theme switch is in the account menu (a signed-out visitor
  keeps an icon button from 861px and a row in the drawer); the phone's reveal-search button and the scrolled-header clip are gone because the shared header's search is one tap on every page.
--}}
@php
    $user = $viewer['user'];
    $cartHref = emall_route('cart.index');
    $nav = \App\Support\HeaderNav::store($categories);
    $account = \App\Support\HeaderNav::account($viewer);
@endphp
<div class="ds-awning" aria-hidden="true"></div>
<x-dot.header class="ds-header" brand-href="{{ url('/') }}" brand-label="Dot.Emall, home" :nav="$nav" :account="$account" :guest="$user ? null : \App\Support\HeaderNav::guest()"
    nav-label="Shop" menu-label="Open menu" drawer-title="Menu" search-label="Search the shop">
    <x-slot:brand>@include('layouts.partials.brand')</x-slot:brand>
    <x-slot:search>
        @include('layouts.partials.shop-search', ['suggestions' => $suggestions ?? null])
    </x-slot:search>
    <x-slot:tools>
        @unless ($user)<x-ds.theme-toggle class="ds-hide-md" />@endunless
        @if ($user && ($switcher ?? false))@include('layouts.partials.dot-switcher', ['user' => $user])@endif
        @if ($user)<livewire:notification-bell />@endif
        <a href="{{ $cartHref }}" class="dh-icon-btn ds-cart-link" {!! emall_pending('cart.index') !!}>
            <x-ds.icon name="cart" :size="24" class="dh-ic" />
            @if ($cartCount > 0)<span class="dh-badge" aria-hidden="true">{{ $cartCount > 99 ? '99+' : $cartCount }}</span><span class="ds-visually-hidden">Cart, {{ $cartCount }} {{ \Illuminate\Support\Str::plural('item', $cartCount) }}</span>@else<span class="ds-visually-hidden">Cart, empty</span>@endif
        </a>
    </x-slot:tools>
    <x-slot:drawer>@include('layouts.partials.store-drawer', ['categories' => $categories, 'items' => $accountItems ?? []])</x-slot:drawer>
    @unless ($user)
        <x-slot:drawer-after><nav class="dh-dnav" aria-label="Preferences"><ul><li><x-ds.theme-toggle variant="menu" /></li></ul></nav><p class="ds-drawer-note">Accounts come from InfoDot. Sign in to buy, sell and follow your orders.</p></x-slot:drawer-after>
    @endunless
</x-dot.header>
@if (isset($location) && trim((string) $location) !== '' || (isset($testmode) && trim((string) $testmode) !== '') || \App\Support\Shell::testModeLabel())
<div class="ds-util">
    <div class="ds-container ds-util__row">
        <div class="ds-util__loc">{{ $location ?? '' }}</div>
        <div class="ds-util__test">@if (isset($testmode) && trim((string) $testmode) !== ''){{ $testmode }}@elseif ($label = \App\Support\Shell::testModeLabel())<x-ds.badge tone="cau" icon="alert-triangle">{{ $label }}</x-ds.badge>
@endif</div>
    </div>
</div>
@endif
