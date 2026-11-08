{{--
  ds.toggle: an on/off switch (a checkbox with role="switch"). Props: label (required), name (required), checked=false, value='1', id=null. The state is in the control itself, not in colour alone: the knob moves.
--}}
@props(['label', 'name', 'checked' => false, 'value' => '1', 'id' => null])
@php($id = $id ?? $name)
<label class="ds-toggle" for="{{ $id }}">
    <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
    <span class="ds-toggle__track" aria-hidden="true"></span>
    <span class="ds-choice__label">{{ $label }}</span>
</label>
