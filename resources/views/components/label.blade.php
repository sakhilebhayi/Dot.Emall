@props(['value'])

<label {{ $attributes->merge(['class' => 'ds-field__label']) }}>
    {{ $value ?? $slot }}
</label>
