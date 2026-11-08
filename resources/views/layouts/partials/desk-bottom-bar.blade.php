{{-- The phone bottom bar: the first four items of the context plus "More", which opens the full menu sheet. Variables: $items, $moreId (dialog id). --}}
<nav class="ds-bottombar" aria-label="Quick links">
    <ul>
        @foreach (array_slice($items, 0, 4) as $item)
            @php
                $r = \App\Support\Shell::first(array_merge([$item['route']], $item['alt'] ?? []));
                $current = request()->routeIs(...$item['match']);
            @endphp
            <li><a href="{{ $r['url'] }}" @if ($current) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif><x-ds.icon :name="$item['icon']" :size="22" /><span>{{ $item['short'] ?? $item['label'] }}</span></a></li>
        @endforeach
        <li><button type="button" data-dialog-open="{{ $moreId }}" aria-haspopup="dialog"><x-ds.icon name="menu" :size="22" /><span>More</span></button></li>
    </ul>
</nav>
