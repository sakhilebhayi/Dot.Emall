{{--
  ds.progress-steps: where the buyer is in checkout. Props: steps (required: list of labels), current (required, 1-based). Done, current and to-do are told apart by the rule colour AND the words
  "done" / "current step" for assistive technology. On phones only the current label shows.
--}}
@props(['steps', 'current' => 1])
<ol {{ $attributes->class('ds-steps') }} aria-label="Checkout progress">
    @foreach ($steps as $i => $label)
        @php($n = $i + 1)
        <li data-state="{{ $n < $current ? 'done' : ($n === (int) $current ? 'current' : 'todo') }}" @if ($n === (int) $current) aria-current="step" @endif>
            <span class="ds-steps__n">@if ($n < $current)<x-ds.icon name="check" :size="14" /> Done @else Step {{ $n }} @endif</span>
            <span class="ds-steps__label">{{ $label }}@if ($n < $current)<span class="ds-visually-hidden"> (done)</span>@endif</span>
        </li>
    @endforeach
</ol>
