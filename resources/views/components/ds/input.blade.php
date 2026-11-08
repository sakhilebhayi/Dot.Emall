{{--
  ds.input: label + text input + hint + error, wired. Props: label (required), name (required), type='text', id=null (defaults to name), hint=null, error=null (null reads the validation
  error for `name`), required=false, value=null (null reads old(name)), reveal=false (a password field only: a "Show" button inside the field that swaps it to plain text and back,
  R-43 polish #25). Extra attributes go to the <input>: autocomplete, inputmode, placeholder, wire:model, min, max, step.
--}}
@props(['label', 'name', 'type' => 'text', 'id' => null, 'hint' => null, 'error' => null, 'required' => false, 'value' => null, 'reveal' => false])
@php
    $id = $id ?? $name;
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ds.field :label="$label" :id="$id" :hint="$hint" :error="$error" :required="$required">
    @if ($reveal && $type === 'password')<div class="ds-input-wrap">@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : ($value ?? old($name)) }}" {{ $attributes->class('ds-input') }} @if ($required) required @endif @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    @if ($reveal && $type === 'password')<button type="button" class="ds-input-wrap__reveal" data-reveal="{{ $id }}" aria-pressed="false" aria-label="Show password"><x-ds.icon name="eye" :size="20" class="when-hidden" /><x-ds.icon name="eye-off" :size="20" class="when-shown" /></button></div>@endif
</x-ds.field>
