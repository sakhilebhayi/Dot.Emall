{{--
  ds.textarea: label + textarea + hint + error. Props as ds.input (no type); rows=4.
--}}
@props(['label', 'name', 'id' => null, 'hint' => null, 'error' => null, 'required' => false, 'value' => null, 'rows' => 4])
@php
    $id = $id ?? $name;
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ds.field :label="$label" :id="$id" :hint="$hint" :error="$error" :required="$required">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" {{ $attributes->class('ds-textarea') }} @if ($required) required @endif @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>{{ $value ?? old($name) }}</textarea>
</x-ds.field>
