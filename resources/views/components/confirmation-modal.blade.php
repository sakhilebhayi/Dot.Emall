@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="ds-jmodal__body">
        <h2 class="ds-jmodal__title"><x-ds.icon name="alert-triangle" :size="22" style="color: var(--crit);" /> {{ $title }}</h2>
        <div class="ds-jmodal__content">{{ $content }}</div>
    </div>
    <div class="ds-jmodal__foot">{{ $footer }}</div>
</x-modal>
