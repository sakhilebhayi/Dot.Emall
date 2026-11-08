{{--
  ds.skeleton: a quiet placeholder while content loads. Props: width='100%', height='1em', ratio=null (e.g. '4 / 5' for a photo). Hidden from assistive technology.
--}}
@props(['width' => '100%', 'height' => '1em', 'ratio' => null])
<span {{ $attributes->class('ds-skeleton') }} style="width: {{ $width }}; @if ($ratio) aspect-ratio: {{ $ratio }}; height: auto; @else height: {{ $height }}; @endif" aria-hidden="true"></span>
