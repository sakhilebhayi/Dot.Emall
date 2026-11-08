{{--
  ds.empty-state: what to show instead of an empty list. Say what is missing, why, and the one thing to do next.
  Props: title (required), icon='box'. Slot: one sentence. Named slot: actions (buttons).
--}}
@props(['title', 'icon' => 'box'])
<div {{ $attributes->class('ds-empty') }}>
    <x-ds.icon :name="$icon" :size="32" />
    <h2>{{ $title }}</h2>
    @if (trim((string) $slot) !== '')<p>{{ $slot }}</p>@endif
    @isset($actions)<div class="ds-page-header__actions">{{ $actions }}</div>@endisset
</div>
