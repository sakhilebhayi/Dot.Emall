{{--
  ds.order-timeline: the WAYBILL. An order told as a parcel's route, with real events only: a step with no timestamp says "Not yet", the seller's tracking details are labelled as from the seller.
  Props: steps (required: list of ['key' => 'paid' (a Status order key, picks the shape), 'label' => 'Paid', 'at' => Carbon|string|null, 'state' => 'done' | 'current' | 'todo' | 'stop', 'note' => null]),
         code=null (the order number, printed in the code face), tracking=null (the seller's tracking number), carrier=null, across=false (steps in a row from 52rem up; use for 6 steps or fewer).
  Cancelled or refunded orders end with a 'stop' step; the remaining steps are simply not listed.
--}}
@props(['steps', 'code' => null, 'tracking' => null, 'carrier' => null, 'across' => false])
<section {{ $attributes->class(['ds-waybill', 'ds-waybill--across' => $across]) }} aria-label="Order progress">
    @if ($code || $tracking)
        <header class="ds-waybill__head">
            <dl>
                @if ($code)<div><dt>Order</dt><dd class="ds-code">{{ $code }}</dd></div>@endif
                @if ($tracking)<div><dt>Tracking from the seller{{ $carrier ? ' ('.$carrier.')' : '' }}</dt><dd class="ds-code">{{ $tracking }}</dd></div>@endif
            </dl>
        </header>
    @endif
    <ol class="ds-waybill__steps">
        @foreach ($steps as $s)
            @php
                $state = $s['state'] ?? 'todo';
                $icon = \App\Support\Status::ORDER[$s['key'] ?? ''][1] ?? 'check-circle';
                $at = $s['at'] ?? null;
                $when = $at instanceof \DateTimeInterface ? $at->format('j M Y, H:i') : ($at ?: null);
            @endphp
            <li class="ds-waybill__step" data-state="{{ $state }}" @if ($state === 'current') aria-current="step" @endif>
                <span class="ds-waybill__mark">@if ($state === 'done')<x-ds.icon name="check" />@else<x-ds.icon :name="$icon" />@endif</span>
                <span>
                    <span class="ds-waybill__word">{{ $s['label'] }}<span class="ds-visually-hidden">{{ ['done' => ', done', 'current' => ', current step', 'todo' => ', not yet', 'stop' => ', stopped'][$state] ?? '' }}</span></span>
                    <span class="ds-waybill__when">{{ $when ?? ($state === 'todo' ? 'Not yet' : '') }}</span>
                    @if (! empty($s['note']))<span class="ds-waybill__note">{{ $s['note'] }}</span>@endif
                </span>
            </li>
        @endforeach
    </ol>
</section>
