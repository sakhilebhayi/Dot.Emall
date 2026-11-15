{{--
  The header of the sign-in, password, verification and legal pages, and of the standalone 500 and 503 pages (R-52: the same shared dot-ui bar and the shop's search, on every page type).
  No account, no switcher, no bell and no cart here: these pages are about getting in, and the 500 and 503 pages must read no session and no database. Variable: $standalone (true: nothing here touches the
  database, the session or a route that may be missing; the search is a plain GET form to /search).
--}}
@php($standalone = $standalone ?? false)
<div class="ds-awning" aria-hidden="true"></div>
<x-dot.header class="ds-header" brand-href="{{ url('/') }}" brand-label="Dot.Emall, home" menu-label="Open menu" drawer-title="Menu" search-label="Search the shop"
    :nav="[['label' => 'Back to the shop', 'href' => url('/')]]">
    <x-slot:brand>@include('layouts.partials.brand')</x-slot:brand>
    <x-slot:search>
        @if ($standalone)
            <x-dot.search name="q" id="site-search-input" :action="url('/search')" placeholder="Search products, brands and stores" label="Search products" />
        @else
            @include('layouts.partials.shop-search')
        @endif
    </x-slot:search>
    <x-slot:tools><x-ds.theme-toggle class="ds-hide-md" /></x-slot:tools>
    <x-slot:drawer-after><nav class="dh-dnav" aria-label="Preferences"><ul><li><x-ds.theme-toggle variant="menu" /></li></ul></nav></x-slot:drawer-after>
</x-dot.header>
