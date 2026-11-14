{{--
  dx.row (R-51): one row of a dashboard list. The whole row is the link (the title's anchor stretches over it). Hairline rules, one height (52, or 40 compact), no card.
  Props: href, title, note=null, n=null (the count, which counts up once on arrival and always ends on this number), go=null (the quiet verb at the right, decoration: the title names the link),
  thumb=null (URL of a real photograph, 44px, explicit size, lazy), compact=false, tone=null ('hatch' draws the "not known yet" texture). Slot: aside (anything at the right, before the verb).
--}}
@props(['href', 'title', 'note' => null, 'n' => null, 'go' => null, 'thumb' => null, 'compact' => false, 'tone' => null])
<li {{ $attributes->class(['dx-row', 'dx-row--compact' => $compact, 'dx-row--nonum' => $n === null, 'dx-row--thumb' => $thumb, 'dx-hatch' => $tone === 'hatch']) }}>
    @if ($n !== null)<span class="dx-row__n" data-count="{{ $n }}">{{ number_format($n) }}</span>@endif
    @if ($thumb)<img class="dx-thumb" src="{{ $thumb }}" alt="" width="44" height="44" loading="lazy" decoding="async">@endif
    <span class="dx-row__t"><strong><a class="dx-row__a" href="{{ $href }}">{{ $title }}</a></strong>@if ($note)<small>{{ $note }}</small>@endif</span>
    @isset($aside){{ $aside }}@endisset
    @if ($go)<span class="dx-row__go" aria-hidden="true"><span>{{ $go }}</span><x-ds.icon name="chevron-right" :size="18" /></span>@endif
</li>
