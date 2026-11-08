{{-- Jetstream's secondary button, in the Shelf Edge skin. New views use <x-ds.button variant="quiet">. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'ds-btn ds-btn--quiet']) }}>
    {{ $slot }}
</button>
