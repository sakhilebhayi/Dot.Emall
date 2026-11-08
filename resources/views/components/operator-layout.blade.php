{{--
  <x-operator-layout>: the OPERATOR area. Moderation, payments, reports, metrics (App\Support\Navigation::operator()). An ink strip across the top marks the context.
  Props and slots are those of <x-desk-shell>. Pages in this area are always noindex.
--}}
@props(['contained' => true])
<x-desk-shell context="operator" :items="\App\Support\Navigation::operator()" label="Operator" sub="Platform desk" strip="ink" :contained="$contained">
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
