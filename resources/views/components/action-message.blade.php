@props(['on'])

<div x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2500); })"
    x-show="shown" x-cloak role="status"
    {{ $attributes->merge(['class' => 'ds-action-message']) }}>
    {{ $slot->isEmpty() ? 'Saved.' : $slot }}
</div>
