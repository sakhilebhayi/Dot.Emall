{{--
  ds.order-timeline: the WAYBILL. An order told as a parcel's route, with real events only: a step with no timestamp says "Not yet", the seller's tracking details are labelled as from the seller.
  Props: steps (required: list of ['key' => 'paid' (a Status order key, picks the shape), 'label' => 'Paid', 'at' => Carbon|string|null, 'state' => 'done' | 'current' | 'todo' | 'stop', 'note' => null]),
         code=null (the order number, printed in the code face), tracking=null (the seller's tracking number), carrier=null, across=false (steps in a row from 52rem up; use for 6 steps or fewer).
  It also prints one sentence that says where the order is in words (so the state is not carried by the markers alone) and a key to the markers that are in use.
  A step that is done but has no recorded time says "Not recorded", never a guessed one.
  Cancelled or refunded orders end with a 'stop' step; the remaining steps are simply not listed.
--}}
@props(['steps', 'code' => null, 'tracking' => null, 'carrier' => null, 'across' => false])
@php
    $list = collect($steps);
    $now = $list->first(fn ($s) => ($s['state'] ?? '') === 'current');
    $stopped = $list->first(fn ($s) => ($s['state'] ?? '') === 'stop');
    $lastDone = $list->last(fn ($s) => ($s['state'] ?? '') === 'done');
    $line = $stopped ? $stopped['label'].'. This order will not go further.' : ($now ? 'Next step: '.$now['label'].'.'.($lastDone ? ' Last done: '.$lastDone['label'].'.' : '') : ($lastDone ? 'Every step is done. Last: '.$lastDone['label'].'.' : null));
    $present = $list->pluck('state')->unique()->all();
    $key = ['done' => 'Done', 'current' => 'Next step', 'todo' => 'Not yet', 'stop' => 'Stopped'];
@endphp
<section {{ $attributes->class(['ds-waybill', 'ds-waybill--across' => $across]) }} aria-label="Order progress">
    @if ($code || $tracking)
        <header class="ds-waybill__head">
            <dl>
                @if ($code)<div><dt>Order</dt><dd class="ds-code">{{ $code }}</dd></div>@endif
                @if ($tracking)<div><dt>Tracking from the seller{{ $carrier ? ' ('.$carrier.')' : '' }}</dt><dd class="ds-code">{{ $tracking }}</dd></div>@endif
            </dl>
        </header>
    @endif
    @if ($line)<p class="ds-waybill__line">{{ $line }}</p>@endif
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
                    <span class="ds-waybill__when">@if ($at instanceof \DateTimeInterface)<span class="ds-waybill__date">{{ $at->format('j M Y') }}</span> <span class="ds-waybill__time">{{ $at->format('H:i') }}</span>@else{{ $when ?? ($state === 'todo' ? 'Not yet' : ($state === 'done' ? 'Not recorded' : '')) }}@endif</span>
                    @if (! empty($s['note']))<span class="ds-waybill__note">{{ $s['note'] }}</span>@endif
                </span>
            </li>
        @endforeach
    </ol>
    <ul class="ds-waybill__key" aria-label="What the markers mean">
        @foreach ($key as $st => $word)@if (in_array($st, $present, true))<li><span class="ds-waybill__swatch" data-state="{{ $st }}" aria-hidden="true"></span>{{ $word }}</li>@endif @endforeach
    </ul>
</section>
