{{--
  <x-seller-layout>: the SELLER DESK. Dashboard, products, orders, returns, shipping, promotions, analytics, store profile (App\Support\Navigation::seller()). A yellow strip across the top marks the context.
  Props and slots are those of <x-desk-shell> (title, description, heading, lead, actions, breadcrumbs, banner, head, scripts, default; contained=true, robots='noindex').
  The store name appears under "Seller desk" in the top bar.
--}}
@props(['contained' => true])
@php $store = null; try { $store = request()->user()?->store; } catch (\Throwable) {} @endphp
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
    @isset($banner)<x-slot:banner>{{ $banner }}</x-slot:banner>
@endisset
    @isset($head)<x-slot:head>{{ $head }}</x-slot:head>
@endisset
    @isset($scripts)<x-slot:scripts>{{ $scripts }}</x-slot:scripts>
@endisset
    {{ $slot }}
</x-desk-shell>
