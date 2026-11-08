{{--
  ds.dropdown: a button that opens a small menu. Props: label (required, the button text or an accessible name when icon-only), icon=null, align='end' | 'start', id=null, iconOnly=false, bare=false (a header-style link button instead of an outlined button).
  Slot: ds.dropdown-item entries (links or buttons), ds.dropdown-sep. Esc and a click outside close it; Up and Down move through items.
--}}
@props(['label', 'icon' => null, 'align' => 'end', 'id' => null, 'iconOnly' => false, 'bare' => false])
@php($id = $id ?? 'dd-'.\Illuminate\Support\Str::random(5))
<div class="ds-dropdown {{ $align === 'start' ? 'ds-dropdown--start' : '' }}">
    @if ($iconOnly)
        <button type="button" class="ds-icon-btn" data-disclosure aria-expanded="false" aria-controls="{{ $id }}" aria-haspopup="menu" aria-label="{{ $label }}" title="{{ $label }}"><x-ds.icon :name="$icon ?? 'dots'" /></button>
    @else
        <button type="button" {{ $attributes->class($bare ? 'ds-header__link' : 'ds-btn ds-btn--quiet ds-btn--md') }} data-disclosure aria-expanded="false" aria-controls="{{ $id }}" aria-haspopup="menu">
            @if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $label }}</span><x-ds.icon name="chevron-down" :size="16" />
        </button>
    @endif
    <div class="ds-dropdown__panel" id="{{ $id }}" role="menu" data-disclosure-panel hidden>{{ $slot }}</div>
</div>
