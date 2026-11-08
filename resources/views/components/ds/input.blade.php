{{--
  ds.input: label + text input + hint + error, wired. Props: label (required), name (required), type='text', id=null (defaults to name), hint=null, error=null (null reads the validation
  error for `name`), required=false, value=null (null reads old(name)). Extra attributes go to the <input>: autocomplete, inputmode, placeholder, wire:model, min, max, step.
--}}
@props(['label', 'name', 'type' => 'text', 'id' => null, 'hint' => null, 'error' => null, 'required' => false, 'value' => null])
@php
    $id = $id ?? $name;
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ds.field :label="$label" :id="$id" :hint="$hint" :error="$error" :required="$required">
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : ($value ?? old($name)) }}" {{ $attributes->class('ds-input') }} @if ($required) required @endif @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
</x-ds.field>
