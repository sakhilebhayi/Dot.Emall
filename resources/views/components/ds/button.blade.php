{{--
  ds.button: the one button.
  Props: variant='primary' (the page's main action, one per view) | 'quiet' | 'danger' | 'link'; size='md' (sm 36px, md 44px, lg 52px);
         href=null (renders <a>); type='button' (use "submit" in forms); icon=null; iconRight=null; loading=false (spinner, disabled, aria-busy);
         block=false (full width); disabled=false.
  Slot: the label (always a word; icon-only controls use ds.icon-button).
  Hover and press follow the stamp verb (ds-core.css): the primary button lifts 1px and the arrow of a 'go' button travels 3px; press settles it.
  Extra attributes pass through: wire:click, data-dialog-open="id", form="id", name, value.
  A submit button carries data-ds-lock so a double click cannot send the form twice (resources/js/app.js).
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'iconRight' => null, 'loading' => false, 'block' => false, 'disabled' => false])
@php
    $classes = ['ds-btn', 'ds-btn--'.$variant, 'ds-btn--'.$size, 'ds-btn--block' => $block, 'is-loading' => $loading, 'ds-btn--go' => (bool) $iconRight, 'ds-btn--lead' => (bool) $icon && ! $iconRight];
    $iconSize = $size === 'sm' ? 16 : 18;
@endphp
@if ($href && ! $disabled && ! $loading)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ds.icon :name="$icon" :size="$iconSize" />@endif
        <span>{{ $slot }}</span>
        @if ($iconRight)<x-ds.icon :name="$iconRight" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled || $loading) @if ($loading) aria-busy="true" @endif @if ($type === 'submit') data-ds-lock @endif>
        @if ($loading)<x-ds.icon name="loader" :size="$iconSize" spin />@elseif ($icon)<x-ds.icon :name="$icon" :size="$iconSize" />@endif
        <span>{{ $slot }}</span>
        @if ($iconRight && ! $loading)<x-ds.icon :name="$iconRight" :size="$iconSize" />@endif
    </button>
@endif
