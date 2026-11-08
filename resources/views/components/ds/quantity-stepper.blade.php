{{--
  ds.quantity-stepper: minus, number, plus. Works inside a plain form and with Livewire (it fires input and change events).
  Props: name='quantity', value=1, min=1, max=null (pass the real stock; the plus button disables at it), label='Quantity', id=null.
--}}
@props(['name' => 'quantity', 'value' => 1, 'min' => 1, 'max' => null, 'label' => 'Quantity', 'id' => null])
@php($id = $id ?? 'qty-'.\Illuminate\Support\Str::random(5))
<div {{ $attributes->class('ds-stepper') }} role="group" aria-label="{{ $label }}">
    <button type="button" data-step="-1" aria-label="Decrease {{ strtolower($label) }}"><x-ds.icon name="minus" :size="18" /></button>
    <input type="number" inputmode="numeric" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" min="{{ $min }}" @if ($max !== null) max="{{ $max }}" @endif aria-label="{{ $label }}">
    <button type="button" data-step="1" aria-label="Increase {{ strtolower($label) }}"><x-ds.icon name="plus" :size="18" /></button>
</div>
