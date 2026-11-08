{{--
  ds.alert: a message that stays on the page. (Messages that confirm an action appear as toasts, see ds.toasts.)
  Props: tone='info' | 'pos' | 'cau' | 'crit'; title=null; banner=false (full width strip under the header); dismissible=false.
  Slot: the message. role="alert" is set for crit, role="status" otherwise.
--}}
@props(['tone' => 'info', 'title' => null, 'banner' => false, 'dismissible' => false])
@php($icon = ['info' => 'info', 'pos' => 'check-circle', 'cau' => 'alert-triangle', 'crit' => 'x-octagon'][$tone] ?? 'info')
<div {{ $attributes->class(['ds-alert', 'ds-alert--'.$tone, 'ds-alert--banner' => $banner]) }} role="{{ $tone === 'crit' ? 'alert' : 'status' }}">
    <x-ds.icon :name="$icon" :size="20" />
    <div class="ds-alert__body">
        @if ($title)<p class="ds-alert__title">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
    @if ($dismissible)<button type="button" class="ds-icon-btn" aria-label="Dismiss" data-alert-close><x-ds.icon name="close" :size="18" /></button>@endif
</div>
