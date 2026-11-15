{{--
  The shop's search pill, in every header that has one (the storefront, the account, the sign-in pages and the error pages). Variable: $suggestions (slot or null, server-rendered rows).
  The shared Dot UI pill, the same control as InfoDot's. Only its look is shared: the form still GETs the shop's own search with ?q=, and the suggestions still come from the shop's own
             search.suggest endpoint (resources/js/app.js fills [data-suggest-list]; dot-ui's results controller drives the arrow keys). The panel stays hidden while it is empty.
--}}
@php($searchUrl = emall_route('search') !== '#' ? emall_route('search') : emall_route('products.index'))
<x-dot.search name="q" id="site-search-input" :action="$searchUrl" :value="is_string(request('q')) ? request('q') : ''" placeholder="Search products, brands and stores" label="Search products" results-nav
    :root-attributes="array_filter(['id' => 'site-search', 'data-suggest-endpoint' => \App\Support\Shell::routeExists('search.suggest') ? route('search.suggest') : null])">
    <x-slot:results><div class="dsr-quick ds-suggest" data-suggest-list>@isset($suggestions){{ $suggestions }}@endisset</div></x-slot:results>
</x-dot.search>
