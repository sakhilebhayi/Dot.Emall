{{--
  dx.none (R-51): an honest empty state inside a dashboard list: one true sentence that says what is missing and when it will show. Props: art=false ('shelf' draws the empty-shelf drawing), top=false (draws the list's top rule).
--}}
@props(['art' => false, 'top' => false])
<div {{ $attributes->class(['dx-none', 'dx-none--top' => $top, 'dx-none--art' => $art]) }}>
    @if ($art === 'shelf')
        <svg class="dx-shelfart" viewBox="0 0 160 88" width="160" height="88" aria-hidden="true" focusable="false">
            <line class="dx-shelfart__board" x1="4" y1="76" x2="156" y2="76"/>
            <line class="dx-shelfart__board" x1="4" y1="40" x2="156" y2="40"/>
            @foreach ([[14, 54, 30], [58, 54, 22], [96, 54, 34]] as [$x, $y, $w])<rect class="dx-shelfart__slot" x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="22" rx="2"/>@endforeach
            @foreach ([[26, 18, 26], [80, 18, 30]] as [$x, $y, $w])<rect class="dx-shelfart__slot" x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="22" rx="2"/>@endforeach
        </svg>
    @endif
    <p>{{ $slot }}</p>
</div>
