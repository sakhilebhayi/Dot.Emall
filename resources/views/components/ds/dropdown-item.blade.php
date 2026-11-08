{{--
  ds.dropdown-item: one entry in a ds.dropdown. Props: href=null (a link) or type='button' (a button; use inside a form for POST actions), icon=null, danger=false, current=false.
--}}
@props(['href' => null, 'icon' => null, 'danger' => false, 'type' => 'button', 'current' => false])
@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class(['ds-dropdown__item', 'ds-dropdown__item--danger' => $danger]) }} @if ($current) aria-current="page" @endif>@if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $slot }}</span></a>
@else
    <button type="{{ $type }}" role="menuitem" {{ $attributes->class(['ds-dropdown__item', 'ds-dropdown__item--danger' => $danger]) }}>@if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $slot }}</span></button>
@endif
