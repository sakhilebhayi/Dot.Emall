<div {{ $attributes->merge(['class' => 'ds-jsection']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="ds-jsection__panel"><div class="ds-jsection__fields">{{ $content }}</div></div>
</div>
