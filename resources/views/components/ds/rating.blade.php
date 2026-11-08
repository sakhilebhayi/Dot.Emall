{{--
  ds.rating: stars with a score and a count. Renders NOTHING when there are no reviews (an empty row of stars reads as a bad rating).
  Props: value (average, 0 to 5), count (review count), showScore=true, showCount=true, href=null (link to the reviews).
  Accessible text: "Rated 4.6 out of 5 from 120 reviews".
--}}
@props(['value' => 0, 'count' => 0, 'showScore' => true, 'showCount' => true, 'href' => null])
@if ((int) $count > 0)
    @php($v = round((float) $value, 1))
    @php($tag = $href ? 'a' : 'span')
    <{{ $tag }} @if ($href) href="{{ $href }}" style="text-decoration: none; color: inherit;" @endif {{ $attributes->class('ds-rating') }}>
        <span class="ds-visually-hidden">Rated {{ number_format($v, 1) }} out of 5 from {{ $count }} {{ \Illuminate\Support\Str::plural('review', $count) }}</span>
        <span class="ds-rating__stars" aria-hidden="true">
            @for ($i = 1; $i <= 5; $i++)<x-ds.icon name="star" :size="16" :class="$v >= $i - 0.25 ? 'is-on' : ''" />@endfor
        </span>
        @if ($showScore)<span class="ds-rating__score" aria-hidden="true">{{ number_format($v, 1) }}</span>@endif
        @if ($showCount)<span aria-hidden="true">({{ number_format((int) $count) }})</span>@endif
    </{{ $tag }}>
@endif
