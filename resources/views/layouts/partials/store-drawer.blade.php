{{--
  The phone menu of the storefront and the account (the body of the shared drawer, which prints the account section and sign-in itself). Variables: $categories (App\Support\Navigation::categories()),
  $items (optional, the account navigation, App\Support\Navigation::account()). Same markup as dot-ui's own drawer rows: a group is a disclosure button, every row is 44px.
--}}
@php
    $first = fn (array $names) => \App\Support\Shell::first($names);
    $row = fn (string $label, array $names, bool $current = false) => ['label' => $label, 'url' => $first($names)['url'], 'name' => $first($names)['name'], 'current' => $current];
    $rows = [
        $row('Stores', ['stores.index'], request()->routeIs('stores.*')),
        $row('Sell on Emall', ['sell.start', 'seller.store.create'], request()->routeIs('sell.start', 'seller.store.create')),
        $row('Help', ['help.index'], request()->routeIs('help.*')),
    ];
@endphp
<nav class="dh-dnav" aria-label="Menu">
    <ul>
        @if (! empty($items))
            <li class="dh-dgroup">
                <button type="button" class="dh-dgroup__btn" data-dh-disclosure aria-expanded="true" aria-controls="dh-drawer-acct"><span class="dh-menu__label">Your account</span><span class="dh-chev" aria-hidden="true"></span></button>
                <ul id="dh-drawer-acct" class="dh-dgroup__list">
                    @foreach ($items as $item)
                        @php($r = \App\Support\Shell::first(array_merge([$item['route']], $item['alt'] ?? [])))
                        <li><a class="dh-dnav__link" href="{{ $r['url'] }}" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif><span class="dh-menu__label">{{ $item['label'] }}</span></a></li>
                    @endforeach
                </ul>
            </li>
        @endif
        <li><a class="dh-dnav__link" href="{{ emall_route('products.index') }}" @if (request()->routeIs('products.index') && ! request()->filled('category')) aria-current="page" @endif><span class="dh-menu__label">All products</span></a></li>
        @foreach ($categories as $i => $c)
            <li class="dh-dgroup">
                <button type="button" class="dh-dgroup__btn" data-dh-disclosure aria-expanded="false" aria-controls="dh-drawer-cat{{ $i }}"><span class="dh-menu__label">{{ $c['name'] }}</span><span class="dh-chev" aria-hidden="true"></span></button>
                <ul id="dh-drawer-cat{{ $i }}" class="dh-dgroup__list" hidden>
                    <li><a class="dh-dnav__link" href="{{ $c['href'] }}"><span class="dh-menu__label">All in {{ $c['name'] }}</span></a></li>
                    @foreach ($c['children'] as $k)
                        <li><a class="dh-dnav__link" href="{{ $k['href'] }}"><span class="dh-menu__label">{{ $k['name'] }}</span></a></li>
                    @endforeach
                </ul>
            </li>
        @endforeach
        @foreach ($rows as $r)
            <li><a class="dh-dnav__link" href="{{ $r['url'] }}" @if ($r['current']) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif><span class="dh-menu__label">{{ $r['label'] }}</span></a></li>
        @endforeach
    </ul>
</nav>
