{{--
  ds.seller-trust: the trust ladder for a store, built ONLY from data the platform really holds. Each rung says what it is, how it is known, and its value.
  Props: rungs (required: list of ['label' =>, 'how' => 'How we know', 'state' => 'checked' | 'claimed' | 'metric' | 'missing', 'value' => null]),
         title='About this seller'.
  checked = confirmed by a system we trust (an InfoDot sign-in, a linked InfoDot company). claimed = typed by the seller, shown as such. metric = measured on Emall (with its sample size in `how`).
  missing = not provided, shown plainly. Never add a rung the platform cannot back, and never call anything "verified" unless the how-line says who verified it.
--}}
@props(['rungs', 'title' => 'About this seller'])
<section {{ $attributes }} aria-label="{{ $title }}">
    <ul class="ds-trust">
        @foreach ($rungs as $r)
            @php
                $state = $r['state'] ?? 'metric';
                $word = ['checked' => 'Checked', 'claimed' => 'Claimed by the seller', 'metric' => '', 'missing' => 'Not provided'][$state] ?? '';
                $icon = ['checked' => 'shield-check', 'claimed' => 'info', 'metric' => 'chart', 'missing' => 'minus-circle'][$state] ?? 'info';
            @endphp
            <li class="ds-trust__rung" data-state="{{ $state }}">
                <x-ds.icon :name="$icon" :size="20" />
                <span class="ds-trust__label">{{ $r['label'] }}<span class="ds-trust__how">{{ $r['how'] ?? '' }}</span></span>
                <span class="ds-trust__value">{{ $r['value'] ?? $word }}@if (! empty($r['value']) && $word)<span class="ds-trust__how">{{ $word }}</span>@endif</span>
            </li>
        @endforeach
    </ul>
</section>
