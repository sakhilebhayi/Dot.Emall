{{--
  op.decide: one operator decision as a button that opens a reason field. The reason is the point: where a decision affects another party it is required, saved in the audit trail and shown to that party.
  Props: bag (required, unique on the page: the validation bag and the form id), action (required, URL), summary (required, the button that opens it), submit=null (the confirm button, defaults to the summary),
         tone='danger' | 'primary' | 'quiet', field='reason' (the input's name), label='Reason', hint=null, templates=[] (text => label, choices copied into the field), required=true, rows=3, method='POST'.
  Slot: extra fields (hidden inputs, a select) inside the form, before the reason.
  Refused decisions come back with the sentence under the field (the bag); the typed text is kept and the block opens by itself.
--}}
@props(['bag', 'action', 'summary', 'submit' => null, 'tone' => 'quiet', 'field' => 'reason', 'label' => 'Reason', 'hint' => null, 'templates' => [], 'required' => true, 'rows' => 3, 'method' => 'POST'])
@php
    $errs = $errors->getBag($bag);
    $isOpen = $errs->any() || session('open_form') === $bag;
    $message = $errs->first($field) ?: ($errs->first('reason') ?: $errs->first('status'));
    $id = $bag.'-'.$field;
@endphp
<details class="op-decide" @if ($isOpen) open @endif>
    <summary class="ds-btn ds-btn--{{ $tone }} ds-btn--sm">{{ $summary }}</summary>
    <form method="POST" action="{{ $action }}" class="op-decide__form">
        @csrf
        @if (strtoupper($method) !== 'POST') @method($method) @endif
        {{ $slot }}
        @if ($templates)
            <x-ds.select :label="'Start from a common reason'" :name="$id.'-template'" :id="$id.'-template'" :options="$templates" :selected="''" placeholder="Choose one, then edit it" data-reason-template="{{ $id }}" />
        @endif
        <x-ds.textarea :label="$label" :name="$field" :id="$id" :rows="$rows" :hint="$hint" :required="$required" :value="session('open_form') === $bag ? old($field) : ''" :error="$message ?: ''" />
        <div class="op-decide__actions">
            <x-ds.button type="submit" :variant="$tone === 'quiet' ? 'primary' : $tone" size="sm">{{ $submit ?? $summary }}</x-ds.button>
        </div>
    </form>
</details>
