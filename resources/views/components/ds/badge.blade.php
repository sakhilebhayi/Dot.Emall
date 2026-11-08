{{--
  ds.badge: a small label. For order and product states use ds.status (it carries the word, shape and tone together).
  Props: tone='neutral' | 'pos' | 'cau' | 'crit' | 'info' | 'ink'; icon=null.
--}}
@props(['tone' => 'neutral', 'icon' => null])
<span {{ $attributes->class(['ds-badge', 'ds-badge--'.$tone => $tone !== 'neutral']) }}>@if ($icon)<x-ds.icon :name="$icon" :size="14" />@endif{{ $slot }}</span>
