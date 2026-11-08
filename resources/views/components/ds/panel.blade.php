{{--
  ds.panel: a flat grouping surface for a form or a block of facts, inside the page column (never floating in a gutter). Props: title=null (an h2).
--}}
@props(['title' => null])
<section {{ $attributes->class('ds-panel') }}>
    @if ($title)<h2 class="ds-panel__title">{{ $title }}</h2>@endif
    {{ $slot }}
</section>
