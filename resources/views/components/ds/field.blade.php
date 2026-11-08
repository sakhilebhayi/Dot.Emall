{{--
  ds.field: the wrapper behind every form control: a label, a hint, the control, an error. Use it for a control the kit does not render itself.
  Props: label (required), id (required, must match the control's id), hint=null, error=null (a string; null reads $errors->first(name) when name is set), name=null, required=false.
  Slot: the control. Give the control aria-describedby="{id}-hint {id}-error" (drop the part that is absent) and aria-invalid="true" when there is an error.
  The ds.input, ds.textarea, ds.select, ds.checkbox, ds.radio and ds.toggle components do this wiring for you.
--}}
@props(['label', 'id', 'hint' => null, 'error' => null, 'name' => null, 'required' => false])
@php($error = $error ?? ($name && isset($errors) ? $errors->first($name) : null))
<div {{ $attributes->class('ds-field') }}>
    <label class="ds-field__label" for="{{ $id }}">{{ $label }}@if ($required)<span class="ds-req" aria-hidden="true">*</span><span class="ds-visually-hidden"> (required)</span>@endif</label>
    @if ($hint)<p class="ds-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    {{ $slot }}
    @if ($error)<p class="ds-field__error" id="{{ $id }}-error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $error }}</span></p>@endif
</div>
