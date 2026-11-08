{{--
  ds.icon-button: a 44px icon-only control. The label is mandatory: it becomes the accessible name and the tooltip.
  Props: icon (required), label (required), href=null (renders <a>), type='button', pressed=null (true/false renders aria-pressed, for toggles such as the wishlist heart), size=22.
--}}
@props(['icon', 'label', 'href' => null, 'type' => 'button', 'pressed' => null, 'size' => 22])
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class('ds-icon-btn') }} aria-label="{{ $label }}" title="{{ $label }}"><x-ds.icon :name="$icon" :size="$size" />{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class('ds-icon-btn') }} aria-label="{{ $label }}" title="{{ $label }}" @if (! is_null($pressed)) aria-pressed="{{ $pressed ? 'true' : 'false' }}" @endif><x-ds.icon :name="$icon" :size="$size" />{{ $slot }}</button>
@endif
