{{--
  ds.radio: a group of native radios under a legend. Props: legend (required), name (required), options (required: value => label, or value => ['label' =>, 'hint' =>]), selected=null, hint=null, error=null.
--}}
@props(['legend', 'name', 'options' => [], 'selected' => null, 'hint' => null, 'error' => null])
@php
    $error = $error ?? (isset($errors) ? $errors->first($name) : null);
    $current = $selected ?? old($name);
@endphp
<fieldset {{ $attributes->class('ds-fieldset') }} @if ($error) aria-describedby="{{ $name }}-error" @endif>
    <legend>{{ $legend }}</legend>
    @if ($hint)<p class="ds-field__hint">{{ $hint }}</p>@endif
    @foreach ($options as $value => $opt)
        @php($text = is_array($opt) ? $opt['label'] : $opt)
        <label class="ds-choice">
            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked((string) $current === (string) $value)>
            <span class="ds-choice__text"><span class="ds-choice__label">{{ $text }}</span>@if (is_array($opt) && ! empty($opt['hint']))<span class="ds-choice__hint">{{ $opt['hint'] }}</span>@endif</span>
        </label>
    @endforeach
    @if ($error)<p class="ds-field__error" id="{{ $name }}-error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $error }}</span></p>@endif
</fieldset>
