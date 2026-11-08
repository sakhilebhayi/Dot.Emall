{{--
  ds.dialog: a modal built on the native <dialog> (focus trap, Esc, inert page, focus returns to the opener). A bottom sheet on phones.
  Props: id (required), title (required, the h2), sheet=true (bottom sheet on phones), drawer=false (slides from the left; used for the menu), lock=false (a click outside does not close it).
  Open it with any element that has data-dialog-open="{id}"; close with data-dialog-close. Slots: default (body), footer (buttons).
--}}
@props(['id', 'title', 'sheet' => true, 'drawer' => false, 'lock' => false])
<dialog id="{{ $id }}" {{ $attributes->class(['ds-dialog', 'ds-dialog--sheet' => $sheet, 'ds-dialog--drawer' => $drawer]) }} aria-labelledby="{{ $id }}-title" @if ($lock) data-modal-lock @endif>
    <div class="ds-dialog__head">
        <h2 id="{{ $id }}-title">{{ $title }}</h2>
        <button type="button" class="ds-icon-btn" data-dialog-close aria-label="Close"><x-ds.icon name="close" /></button>
    </div>
    <div class="ds-dialog__body">{{ $slot }}</div>
    @isset($footer)<div class="ds-dialog__foot">{{ $footer }}</div>@endisset
</dialog>
