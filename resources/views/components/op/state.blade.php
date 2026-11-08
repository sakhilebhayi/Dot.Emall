{{--
  op.state: ok / attention / unknown as a word, a shape and a tone. Unknown is not ok: it means there is no record. Props: state (required: ok | attention | unknown), label=null (overrides the word).
--}}
@props(['state', 'label' => null])
@php([$word, $icon, $tone] = ['ok' => ['OK', 'check-circle', 'pos'], 'attention' => ['Needs attention', 'alert-triangle', 'cau'], 'unknown' => ['Unknown', 'help', 'neutral']][$state] ?? ['Unknown', 'help', 'neutral'])
<x-ds.badge :tone="$tone" :icon="$icon" class="op-state">{{ $label ?? $word }}</x-ds.badge>
