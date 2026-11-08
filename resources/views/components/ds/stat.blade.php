{{--
  ds.stat: one figure with a rule over it. Never a tile: do not put four of them in equal boxes; give one of them the weight and let the rest follow.
  Props: label (required), value (required, already formatted), note=null.
--}}
@props(['label', 'value', 'note' => null])
<div {{ $attributes->class('ds-stat') }}>
    <span class="ds-stat__label">{{ $label }}</span>
    <span class="ds-stat__value">{{ $value }}</span>
    @if ($note)<span class="ds-stat__note">{{ $note }}</span>@endif
</div>
