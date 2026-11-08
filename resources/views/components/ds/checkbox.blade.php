{{--
  ds.checkbox: a native checkbox with a label and optional hint, the whole row is the target (44px). Props: label (required), name (required), value='1', checked=false, hint=null, id=null, error=null.
--}}
@props(['label', 'name', 'value' => '1', 'checked' => false, 'hint' => null, 'id' => null, 'error' => null])
@php
    $id = $id ?? $name.'-'.$value;
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
@endphp
<div class="ds-field">
    <label class="ds-choice" for="{{ $id }}">
        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($checked || old($name) == $value) {{ $attributes }} @if ($hint) aria-describedby="{{ $id }}-hint" @endif @if ($error) aria-invalid="true" @endif>
        <span class="ds-choice__text"><span class="ds-choice__label">{{ $label }}</span>@if ($hint)<span class="ds-choice__hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif</span>
    </label>
    @if ($error)<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $error }}</span></p>@endif
</div>
