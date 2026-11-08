@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));
$maxWidth = ['sm' => 'ds-jmodal--sm', 'md' => 'ds-jmodal--md', 'lg' => 'ds-jmodal--lg', 'xl' => 'ds-jmodal--xl', '2xl' => 'ds-jmodal--xl'][$maxWidth ?? 'md'];
@endphp

{{-- Jetstream's Livewire modal (state through wire:model). Kit pages use <x-ds.dialog>; this one stays for the Livewire forms. --}}
<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="ds-jmodal"
    role="dialog" aria-modal="true"
    style="display: none;"
>
    <div class="ds-jmodal__scrim" x-show="show" x-on:click="show = false"></div>
    <div class="ds-jmodal__sheet {{ $maxWidth }}" x-show="show" x-trap.inert.noscroll="show">
        {{ $slot }}
    </div>
</div>
