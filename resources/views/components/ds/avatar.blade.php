{{--
  ds.avatar: a photo, or initials on a soft ground. Props: name (required), src=null, size='md' | 'lg'. Decorative: the name is announced where it is shown beside it.
--}}
@props(['name', 'src' => null, 'size' => 'md'])
@if ($src)
    <img src="{{ $src }}" alt="" {{ $attributes->class(['ds-avatar', 'ds-avatar--lg' => $size === 'lg']) }} width="40" height="40" loading="lazy">
@else
    <span {{ $attributes->class(['ds-avatar', 'ds-avatar--lg' => $size === 'lg']) }} aria-hidden="true">{{ \App\Support\Shell::initials($name) }}</span>
@endif
