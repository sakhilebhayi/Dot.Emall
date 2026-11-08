{{--
  ds.confirm-dialog: "are you sure" for an action that cannot be undone, as a form. Props: id (required), title (required), action (required, URL), method='POST' (PUT, PATCH, DELETE are spoofed),
  confirmLabel='Confirm', tone='danger' | 'primary'. Slot: what will happen, in a sentence (name the thing and say it cannot be undone). Open with data-dialog-open="{id}".
  The cancel button is first in the tab order and the default; the confirm button names the action ("Delete product"), never just "OK".
--}}
@props(['id', 'title', 'action', 'method' => 'POST', 'confirmLabel' => 'Confirm', 'tone' => 'danger'])
<x-ds.dialog :id="$id" :title="$title">
    <form method="POST" action="{{ $action }}" id="{{ $id }}-form">
        @csrf
        @if (strtoupper($method) !== 'POST') @method($method) @endif
        <div class="ds-stack">{{ $slot }}</div>
    </form>
    <x-slot:footer>
        <x-ds.button variant="quiet" data-dialog-close autofocus>Keep it</x-ds.button>
        <x-ds.button :variant="$tone" type="submit" form="{{ $id }}-form">{{ $confirmLabel }}</x-ds.button>
    </x-slot:footer>
</x-ds.dialog>
