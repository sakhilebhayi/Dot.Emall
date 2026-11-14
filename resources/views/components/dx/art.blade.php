{{--
  dx.art (R-51): the arrival band's drawing, from the product's own devices (the label sheet and its perforation, the price tag, the stamp), in tokens only. Decorative: aria-hidden, no
  text, no figures, nothing that looks like a control or a record. It settles in once on arrival (CSS, transform and opacity) and never moves again. Prop: kind.
--}}
@props(['kind' => 'seller'])
<svg class="dx-art dx-art--{{ $kind }}" viewBox="0 0 240 132" width="240" height="132" aria-hidden="true" focusable="false">
    @if ($kind === 'buyer')
        @foreach ([[8, 30, -5], [74, 12, 2], [140, 34, -2]] as $i => [$x, $y, $r])
            <g class="dx-art__a dx-art__a--{{ $i + 1 }}"><g transform="translate({{ $x }} {{ $y }}) rotate({{ $r }} 40 44)">
                <path class="dx-art__sheet" d="M14 2h52a4 4 0 0 1 4 4v74a4 4 0 0 1-4 4H14a4 4 0 0 1-4-4V6a4 4 0 0 1 4-4z"/>
                <circle class="dx-art__hole" cx="40" cy="14" r="4.5"/>
                <path class="dx-art__string" d="M40 9.5c0-14 -2-26 -30-30"/>
                <rect class="dx-art__bar" x="22" y="34" width="36" height="5" rx="1.5"/>
                <rect class="dx-art__bar dx-art__bar--s" x="22" y="46" width="24" height="4" rx="1.5"/>
                <path class="dx-art__chev" d="M26 64l8 6-8 6"/>
            </g></g>
        @endforeach
    @elseif ($kind === 'operator')
        @foreach ([[30, 36], [18, 20], [6, 4]] as $i => [$x, $y])
            <g class="dx-art__a dx-art__a--{{ $i + 1 }}"><g transform="translate({{ $x }} {{ $y }})">
                <rect class="dx-art__sheet" x="0" y="0" width="150" height="86" rx="4"/>
                <rect class="dx-art__bar" x="14" y="16" width="70" height="5" rx="1.5"/>
                <rect class="dx-art__bar dx-art__bar--s" x="14" y="30" width="104" height="4" rx="1.5"/>
                <rect class="dx-art__bar dx-art__bar--s" x="14" y="42" width="88" height="4" rx="1.5"/>
            </g></g>
        @endforeach
        <g class="dx-art__a dx-art__a--4"><g transform="translate(150 66) rotate(-12)">
            <circle class="dx-art__stamp" cx="30" cy="30" r="27"/>
            <circle class="dx-art__stamp dx-art__stamp--in" cx="30" cy="30" r="20"/>
            <path class="dx-art__chev" d="M24 20l12 10-12 10"/>
        </g></g>
    @else
        @foreach ([[44, 40], [26, 22], [8, 4]] as $i => [$x, $y])
            <g class="dx-art__a dx-art__a--{{ $i + 1 }}"><g transform="translate({{ $x }} {{ $y }}) rotate({{ [-1, 1, -2][$i] }} 80 44)">
                <rect class="dx-art__sheet" x="0" y="0" width="160" height="88" rx="4"/>
                <line class="dx-art__perf" x1="40" y1="0" x2="40" y2="88"/>
                <circle class="dx-art__notch" cx="40" cy="0" r="5"/>
                <circle class="dx-art__notch" cx="40" cy="88" r="5"/>
                <path class="dx-art__chev" d="M14 34l12 10-12 10"/>
                <rect class="dx-art__bar" x="56" y="22" width="78" height="5" rx="1.5"/>
                <rect class="dx-art__bar dx-art__bar--s" x="56" y="36" width="56" height="4" rx="1.5"/>
                <rect class="dx-art__bar dx-art__bar--s" x="56" y="48" width="66" height="4" rx="1.5"/>
                <rect class="dx-art__tape" x="56" y="64" width="40" height="8" rx="1.5"/>
            </g></g>
        @endforeach
    @endif
</svg>
