{{--
  op.chips: filter chips that are links (a filter is a real GET, so it can be shared and bookmarked). Props: items (required: list of ['label', 'href', 'current' => bool, 'count' => ?int]), label='Filter'.
--}}
@props(['items', 'label' => 'Filter'])
<nav aria-label="{{ $label }}">
    <ul class="op-chips">
        @foreach ($items as $item)
            <li><a href="{{ $item['href'] }}" @if (! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}@if (isset($item['count']) && $item['count'] !== null)<span class="op-chips__n">{{ number_format($item['count']) }}</span>@endif</a></li>
        @endforeach
    </ul>
</nav>
