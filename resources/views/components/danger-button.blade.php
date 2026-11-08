{{-- Jetstream's destructive button, in the Shelf Edge skin. New views use <x-ds.button variant="danger">. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'ds-btn ds-btn--danger']) }}>
    {{ $slot }}
</button>
