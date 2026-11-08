@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="ds-jmodal__body">
        <h2 class="ds-jmodal__title">{{ $title }}</h2>
        <div class="ds-jmodal__content">{{ $content }}</div>
    </div>
    <div class="ds-jmodal__foot">{{ $footer }}</div>
</x-modal>
