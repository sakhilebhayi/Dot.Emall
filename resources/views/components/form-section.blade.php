@props(['submit'])

<div {{ $attributes->merge(['class' => 'ds-jsection']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <form wire:submit="{{ $submit }}" class="ds-jsection__panel">
        <div class="ds-jsection__fields">{{ $form }}</div>
        @if (isset($actions))<div class="ds-jsection__actions">{{ $actions }}</div>@endif
    </form>
</div>
