{{--
  <x-report-form>: "Report" as a small disclosure with a form: a reason and optional details, posted to the operator queue. Works without script.
  Props: action (required, the POST URL), bag (required, a unique name for this form's error bag so one failing report does not mark the others), label='Report', what='this' (for the heading: "Report this review"),
         id=null.
  A report never hides anything by itself; the sentence in the form says so.
--}}
@props(['action', 'bag', 'label' => 'Report', 'what' => 'this', 'id' => null])
@php
    $bagErrors = $errors->getBag($bag);
    $uid = $id ?? 'report-'.$bag;
@endphp
<details {{ $attributes->class('rpt') }} @if ($bagErrors->any()) open @endif>
    <summary class="rpt__summary"><x-ds.icon name="flag" :size="16" />{{ $label }}</summary>
    <form method="POST" action="{{ $action }}" class="rpt__form">
        @csrf
        <p class="rpt__intro">Tell the marketplace team what is wrong with {{ $what }}. A report does not remove anything by itself; a person looks at it.</p>
        <div class="ds-field">
            <label class="ds-field__label" for="{{ $uid }}-reason">What is wrong?</label>
            <div class="ds-select-wrap">
                <select class="ds-select" id="{{ $uid }}-reason" name="reason" required @if ($bagErrors->has('reason')) aria-invalid="true" aria-describedby="{{ $uid }}-err" @endif>
                    <option value="">Choose a reason</option>
                    @foreach (['spam' => 'Spam or an advert', 'fake' => 'Looks fake', 'offensive' => 'Offensive', 'off_topic' => 'Not about this', 'personal_info' => 'Shows personal information', 'other' => 'Something else'] as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            @if ($bagErrors->has('reason'))<p class="ds-field__error" id="{{ $uid }}-err"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $bagErrors->first('reason') }}</span></p>@endif
        </div>
        <div class="ds-field">
            <label class="ds-field__label" for="{{ $uid }}-details">More detail (optional)</label>
            <textarea class="ds-textarea" id="{{ $uid }}-details" name="details" rows="3" maxlength="1000"></textarea>
        </div>
        <x-ds.button type="submit" variant="quiet" size="sm">Send report</x-ds.button>
    </form>
</details>
