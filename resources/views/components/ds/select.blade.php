{{--
  ds.select: label + native select + hint + error. Props: label (required), name (required), options=[] (value => label map, or the slot of <option>s), selected=null, placeholder=null (an empty first option),
  id=null, hint=null, error=null, required=false. A native select keeps the phone's own picker, which is the best control on a touch screen.
--}}
@props(['label', 'name', 'options' => [], 'selected' => null, 'placeholder' => null, 'id' => null, 'hint' => null, 'error' => null, 'required' => false])
@php
    $id = $id ?? $name;
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
    $current = $selected ?? old($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-ds.field :label="$label" :id="$id" :hint="$hint" :error="$error" :required="$required">
    <div class="ds-select-wrap">
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->class('ds-select') }} @if ($required) required @endif @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $value => $text)<option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $text }}</option>@endforeach
            {{ $slot }}
        </select>
        <x-ds.icon name="chevron-down" :size="18" />
    </div>
</x-ds.field>
