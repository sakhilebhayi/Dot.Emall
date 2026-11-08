@if (request()->is('guest/orders/*'))
    <x-error-layout :code="404" heading="We couldn't find that order" message="The link may be mistyped, may have expired, or may have been replaced by a newer one. For your privacy this page does not say which." hint="If you ordered as a guest, we can send the link again to the e-mail address you ordered with.">
        <x-slot:actions>
            <x-ds.button :href="url('/help/order-link')" icon="mail">Get my order link again</x-ds.button>
            <x-ds.button :href="url('/')" variant="quiet" icon="home">Back to the shop</x-ds.button>
        </x-slot:actions>
    </x-error-layout>
@else
    <x-error-layout :code="404" heading="We couldn't find that page" message="The page may have moved, been renamed, or never existed. Check the link, or start again from the shop." />
@endif
