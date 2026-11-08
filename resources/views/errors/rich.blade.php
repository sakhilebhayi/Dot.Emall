{{--
  errors.rich: an error page drawn inside the storefront layout, for the errors where the person still has a session (403, 404, 419, 429). Called by <x-error-layout :rich="true">,
  which falls back to the standalone page if this view throws. Variables: $code, $heading, $message, $hint, $actions (slot or null).
  The label (code, what happened, the way out) sits beside a search box and the top categories, so a wrong address is never a dead end.
--}}
@php
    $viewer = \App\Support\Navigation::viewer();
    $cats = array_slice(\App\Support\Navigation::categories(), 0, 8);
    $searchUrl = emall_route('search') !== '#' ? emall_route('search') : emall_route('products.index');
    $allCategories = emall_route('categories.index') !== '#' ? emall_route('categories.index') : emall_route('products.index');
@endphp
<x-store-layout robots="noindex" :contained="true">
    <x-slot:title>{{ $heading }}</x-slot:title>
    <section class="ds-err" aria-labelledby="err-h">
        <div class="ds-label ds-err__label">
            <div class="ds-label__main">
                <span class="ds-label__from">Error {{ $code }}</span>
                <p class="ds-error-code" aria-hidden="true">{{ $code }}</p>
                <h1 id="err-h">{{ $heading }}</h1>
            </div>
            <div class="ds-label__perf" aria-hidden="true"></div>
            <div class="ds-auth__body">
                <p>{{ $message }}</p>
                @if ($hint)<p class="ds-muted">{{ $hint }}</p>@endif
                <div class="ds-page-header__actions">
                    @if (isset($actions) && trim((string) $actions) !== ''){{ $actions }}@else
                        <x-ds.button :href="url('/')" icon="home">Back to the shop</x-ds.button>
                        @if ($viewer['user'])
                            <x-ds.button :href="emall_route('orders.index')" variant="quiet" icon="receipt">Your orders</x-ds.button>
                        @else
                            <x-ds.button :href="url('/login')" variant="quiet">Sign in</x-ds.button>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="ds-err__find">
            <h2>Look for it</h2>
            <form role="search" method="get" action="{{ $searchUrl }}" class="ds-err__search">
                <label class="ds-visually-hidden" for="err-q">Search products, brands and stores</label>
                <input class="ds-input" id="err-q" type="search" name="q" placeholder="Search products, brands and stores" autocomplete="off" enterkeyhint="search">
                <x-ds.button type="submit" icon="search">Search</x-ds.button>
            </form>
            @if (count($cats))
                <h2>Or start from a category</h2>
                <ul class="ds-catlist">
                    @foreach ($cats as $c)<li><a href="{{ $c['href'] }}">{{ $c['name'] }}<x-ds.icon name="chevron-right" :size="18" /></a></li>@endforeach
                    <li><a href="{{ $allCategories }}">All categories<x-ds.icon name="arrow-right" :size="18" /></a></li>
                </ul>
            @endif
            <p class="ds-error-eco">Dot.Emall is part of <a href="{{ config('ecosystem.platforms.infodot.url', 'https://infodot.co.za') }}">InfoDot and the rest of the Dot Ecosystem</a>. If this keeps happening, tell the person who sent you the link.</p>
        </div>
    </section>
</x-store-layout>
