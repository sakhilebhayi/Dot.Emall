{{-- The first version's status chip, now a thin wrapper over <x-ds.status> (word + shape + tone). Prefer <x-ds.status> in new views. --}}
@props(['status', 'size' => 'md'])
@php($value = $status instanceof \App\Enums\OrderStatus ? $status->value : (string) $status)
<x-ds.status kind="order" :state="$value" {{ $attributes }} />
