{{--
  op.age: how long ago, in words, with the exact time on hover and for assistive technology. Props: at (a date or null), none='-'. Never a guess: a missing date prints `none`.
--}}
@props(['at' => null, 'none' => '-'])
@php($at = $at ? \Illuminate\Support\Carbon::parse($at) : null)
@if ($at)<time datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('d M Y H:i') }}">{{ $at->diffForHumans(null, true) }}</time>@else<span>{{ $none }}</span>@endif
