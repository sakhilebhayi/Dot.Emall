{{--
  ds.empty-state: what to show instead of an empty list. Say what is missing, why, and the one thing to do next.
  Drawn as a ticket stub (R-43 polish #24, D-188): a punched stub on the left, a perforation, the words on the right, the yellow label strip across the top. The stub is the picture;
  there is no icon slot, so a missing icon can never leave a gap. `icon` is still accepted so older callers do not break, and is ignored.
  Props: title (required), icon=null (ignored). Slot: one sentence. Named slot: actions (buttons).
--}}
@props(['title', 'icon' => null])
<div {{ $attributes->class('ds-empty') }}>
    <span class="ds-empty__stub" aria-hidden="true"><span class="ds-empty__punch"></span></span>
    <div class="ds-empty__text">
        <h2>{{ $title }}</h2>
        @if (trim((string) $slot) !== '')<p>{{ $slot }}</p>@endif
        @isset($actions)<div class="ds-page-header__actions">{{ $actions }}</div>@endisset
    </div>
</div>
