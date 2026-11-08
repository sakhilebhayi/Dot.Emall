{{--
  ds.icon: one inline SVG from App\Support\Icons (24 grid, 1.75 stroke, round caps). The names are listed in DESIGN.md ("Icons").
  Props: name (required), size=20 (px), label=null (accessible name; leave null when the icon sits beside a visible word), spin=false.
--}}
@props(['name', 'size' => 20, 'label' => null, 'spin' => false])
<svg {{ $attributes->class(['ds-icon', 'ds-icon--spin' => $spin]) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! \App\Support\Icons::markup($name) !!}</svg>
