{{--
  ds.empty-state: what to show instead of an empty list. Say what is missing, why, and the one thing to do next.
  Drawn as a ticket stub (R-43 polish #24, D-188): a punched stub on the left, a perforation, the words on the right, the yellow label strip across the top. The stub is the picture;
  The punch carries the caller's glyph (cart, heart, receipt, bell...) so a cart, a wishlist, the orders and the alerts do not share one grey swatch (jury 2 #5); a name the icon set
  does not have is left out and the punch stays an empty dashed ring, so a missing icon can never leave a gap or draw the fallback square.
  Props: title (required), icon=null (a name from the icon set). Slot: one sentence. Named slot: actions (buttons).
--}}
@props(['title', 'icon' => null])
@php($glyph = $icon && \App\Support\Icons::has($icon))
<div {{ $attributes->class('ds-empty') }}>
    <span class="ds-empty__stub" aria-hidden="true"><span class="ds-empty__punch @if ($glyph) ds-empty__punch--glyph @endif">@if ($glyph)<x-ds.icon :name="$icon" :size="22" />@endif</span></span>
    <div class="ds-empty__text">
        <h2>{{ $title }}</h2>
        @if (trim((string) $slot) !== '')<p>{{ $slot }}</p>@endif
        @isset($actions)<div class="ds-page-header__actions">{{ $actions }}</div>@endisset
    </div>
</div>
