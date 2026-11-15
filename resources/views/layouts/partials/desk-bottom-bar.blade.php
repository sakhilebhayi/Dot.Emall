{{-- The phone bottom bar (R-52: at most five targets, each 44px or more): the first four items of the context plus "More", which opens the header's own drawer (#dh-drawer) with the full menu, the account and the platform switcher. Variable: $items. --}}
<nav class="ds-bottombar" aria-label="Quick links">
    <ul>
        @foreach (array_slice($items, 0, 4) as $item)
            @php
                $r = \App\Support\Shell::first(array_merge([$item['route']], $item['alt'] ?? []));
                $current = request()->routeIs(...$item['match']);
            @endphp
            <li><a href="{{ $r['url'] }}" @if ($current) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif><x-ds.icon :name="$item['icon']" :size="22" /><span>{{ $item['short'] ?? $item['label'] }}</span></a></li>
        @endforeach
        <li><button type="button" data-dh-menu-button aria-controls="dh-drawer" aria-expanded="false" aria-haspopup="dialog"><x-ds.icon name="menu" :size="22" /><span>More</span></button></li>
    </ul>
</nav>
