{{-- Navigation links for the rail, the phone menu sheet and the account sub-nav. Variables: $items (App\Support\Navigation list). Renders <a> elements only; the caller supplies the wrapper. --}}
@foreach ($items as $item)
    @php
        $r = \App\Support\Shell::first(array_merge([$item['route']], $item['alt'] ?? []));
        $current = request()->routeIs(...$item['match']);
    @endphp
    <a href="{{ $r['url'] }}" @if ($current) aria-current="page" @endif @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif>
        @if (empty($plain))<x-ds.icon :name="$item['icon']" :size="20" />@endif<span>{{ $item['label'] }}</span>
    </a>
@endforeach
