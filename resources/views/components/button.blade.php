{{-- Jetstream's primary button, in the Shelf Edge skin. New views use <x-ds.button>. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ds-btn ds-btn--primary']) }} data-ds-lock>
    {{ $slot }}
</button>
