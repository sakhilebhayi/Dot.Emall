{{--
  <x-seller-layout>: the SELLER DESK. Dashboard, products, orders, returns, shipping, promotions, analytics, ledger, reviews, store profile (App\Support\Navigation::seller()). A yellow strip across the top marks the context.
  Props and slots are those of <x-desk-shell> (title, description, heading, lead, actions, breadcrumbs, banner, head, scripts, default; contained=true, robots='noindex').
  The store name appears under "Seller desk" in the top bar. A store that is not open to shoppers (draft, waiting for review, suspended, closed by its owner) says so in a strip under the bar on every page, with the Emall team's reason.
--}}
@props(['contained' => true])
@php
    $store = null;
    try { $store = request()->user()?->store; } catch (\Throwable) {}
    $closed = null;
    if ($store && ! request()->routeIs('seller.store.status')) {
        $closed = match (true) {
            $store->status === \App\Enums\StoreStatus::Suspended => ['crit', 'Your store is suspended and closed to shoppers.'.($store->status_reason ? ' Reason from the Emall team: '.$store->status_reason : ''), 'See your store status'],
            $store->status === \App\Enums\StoreStatus::PendingReview => ['info', 'Your store is waiting for the Emall team to review it. Shoppers cannot see it yet.', 'See your store status'],
            $store->status === \App\Enums\StoreStatus::Draft => ['cau', 'Your store is a draft and closed to shoppers.'.($store->status_reason ? ' The Emall team sent it back: '.$store->status_reason : ' Finish the checklist and send it for review.'), 'Open the checklist'],
            ! $store->is_active => ['cau', 'Your store is closed. Your listings are hidden from shoppers until you reopen it.', 'Reopen it in your store profile'],
            default => null,
        };
    }
@endphp
<x-desk-shell context="seller" :items="\App\Support\Navigation::seller()" label="Seller desk" :sub="$store?->name" strip="awning" :contained="$contained">
    <x-slot:title>{{ $title ?? '' }}</x-slot:title>
    @isset($description)<x-slot:description>{{ $description }}</x-slot:description>
@endisset
    @isset($heading)<x-slot:heading>{{ $heading }}</x-slot:heading>
@endisset
    @isset($lead)<x-slot:lead>{{ $lead }}</x-slot:lead>
@endisset
    @isset($actions)<x-slot:actions>{{ $actions }}</x-slot:actions>
@endisset
    @isset($breadcrumbs)<x-slot:breadcrumbs>{{ $breadcrumbs }}</x-slot:breadcrumbs>
@endisset
    @if ($closed || isset($banner))<x-slot:banner>
        @if ($closed)<x-ds.alert :tone="$closed[0]" banner><span>{{ $closed[1] }} <a href="{{ $closed[0] === 'cau' && str_starts_with($closed[2], 'Reopen') ? route('seller.store.edit') : route('seller.store.status') }}">{{ $closed[2] }}</a>.</span></x-ds.alert>
@endif
        @isset($banner){{ $banner }}@endisset
    </x-slot:banner>
@endif
    @isset($head)<x-slot:head>{{ $head }}</x-slot:head>
@endisset
    @isset($scripts)<x-slot:scripts>{{ $scripts }}</x-slot:scripts>
@endisset
    {{ $slot }}
</x-desk-shell>
