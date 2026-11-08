@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])
{{-- Jetstream's banner (flash.banner in the session, or a banner-message event from a Livewire component), as a page-width alert strip. --}}
<div x-data="{{ json_encode(['show' => (bool) $message, 'style' => $style, 'message' => $message]) }}" x-show="show && message" x-cloak
     x-on:banner-message.window="style = event.detail.style; message = event.detail.message; show = true;"
     class="ds-alert ds-alert--banner" :class="{ 'ds-alert--pos': style == 'success', 'ds-alert--crit': style == 'danger', 'ds-alert--cau': style == 'warning', 'ds-alert--info': style != 'success' && style != 'danger' && style != 'warning' }"
     role="status" style="display: none;">
    <span aria-hidden="true"></span>
    <p x-text="message"></p>
    <button type="button" class="ds-icon-btn" aria-label="Dismiss" x-on:click="show = false"><x-ds.icon name="close" :size="18" /></button>
</div>
