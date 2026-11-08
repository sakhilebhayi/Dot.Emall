@php $guest = $mode === 'guest'; @endphp
<x-dynamic-component :component="$guest ? 'store-layout' : 'account-layout'" :robots="'noindex'">
    <x-slot:title>Return an item</x-slot:title>
    <x-slot:heading>Return: {{ $item->displayName() }}</x-slot:heading>
    <x-slot:lead>From order {{ $order->reference() }}. You can ask within {{ $windowDays }} days of delivery. The seller then has {{ $respondDays }} days to answer.</x-slot:lead>

    <form method="POST" action="{{ route(($guest ? 'guest.' : '').'orders.returns.store', [$order, $item]) }}" enctype="multipart/form-data" class="co-form">
        @csrf
        <fieldset class="ds-fieldset">
            <legend>Why are you returning it?</legend>
            @foreach ($reasons as $reason)
                <label class="ds-choice">
                    <input type="radio" name="reason_category" value="{{ $reason->value }}" @checked(old('reason_category') === $reason->value) required>
                    <span class="ds-choice__text"><span class="ds-choice__label">{{ $reason->label() }}</span><span class="ds-choice__hint">{{ $reason->sellerPaysReturn() ? 'The seller pays for the return.' : 'You pay for the return.' }}</span></span>
                </label>
            @endforeach
            @error('reason_category')<p class="ds-field__error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
        </fieldset>

        <x-ds.textarea label="Tell the seller what is wrong" name="note" id="ret-note" rows="4" :value="old('note')" hint="Required if you chose Something else. The seller reads this before they decide." />

        <div class="ds-field">
            <label class="ds-field__label" for="ret-photos">Photos (optional)</label>
            <p class="ds-field__hint" id="ret-photos-hint">Up to {{ $maxPhotos }} JPEG, PNG or WebP images, 4 MB each. Show the fault or the label. Only you, the seller and the Emall team can see them.</p>
            <input class="ds-input" type="file" id="ret-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="ret-photos-hint">
            @error('photos')<p class="ds-field__error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
            @error('order_item')<p class="ds-field__error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
        </div>

        <p class="ds-muted">Delivery fees are not refunded by a return. The refund is the price of the item after any promo share, recorded when the seller confirms it is back. <a href="{{ route('help.returns') }}">How returns work</a>.</p>

        <div class="co-stepact">
            <x-ds.button type="submit" icon="return">Ask for a return</x-ds.button>
            <x-ds.button :href="$guest ? route('guest.orders.show', $order) : route('orders.show', $order)" variant="quiet">Cancel</x-ds.button>
        </div>
    </form>
</x-dynamic-component>
