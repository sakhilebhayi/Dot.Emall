@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'ds-field__error']) }}><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>
@enderror
