{{--
  ds.theme-toggle: light and dark. Writes class="light" or "dark" on <html> and keeps the choice in localStorage ("emall-theme"); with no choice the system decides.
  Props: variant='icon' (44px button) | 'menu' (a dropdown row with the words). The button says what it will do, so its name is "Switch to dark theme" or "Switch to light theme" (set by CSS-free text: both are present, one shown).
--}}
@props(['variant' => 'icon'])
@if ($variant === 'menu')
    <button type="button" role="menuitem" class="ds-dropdown__item" data-theme-toggle>
        <x-ds.icon name="moon" :size="18" class="when-light" /><x-ds.icon name="sun" :size="18" class="when-dark" />
        <span class="when-light">Switch to dark theme</span><span class="when-dark">Switch to light theme</span>
    </button>
@else
    <button type="button" {{ $attributes->class('ds-icon-btn') }} data-theme-toggle>
        <x-ds.icon name="moon" class="when-light" /><x-ds.icon name="sun" class="when-dark" />
        <span class="ds-visually-hidden when-light">Switch to dark theme</span><span class="ds-visually-hidden when-dark">Switch to light theme</span>
    </button>
@endif
