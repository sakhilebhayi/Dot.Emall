<x-store-layout>
    <x-slot:title>Get your order link again</x-slot:title>
    <x-slot:description>Lost the e-mail with your order link, or the link stopped working? We send it again to the address you ordered with.</x-slot:description>
    <x-slot:heading>Get your order link again</x-slot:heading>
    <x-slot:lead>For orders you placed without an account. The link goes only to the e-mail address you ordered with.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <form method="POST" action="{{ route('help.order-link.send') }}" class="ds-stack" novalidate>
            @csrf
            <x-ds.input label="E-mail address you ordered with" name="email" id="link-email" type="email" :value="old('email')" :error="$errors->first('email')" required autocomplete="email" inputmode="email" />
            <div><x-ds.button type="submit" icon="mail">Send me the link</x-ds.button></div>
        </form>
        <p class="ds-muted">We send the links for your {{ \App\Services\Guests\GuestLinkResender::MAX_ORDERS }} most recent guest orders. We always show this same message, so nobody can use the form to find out who has bought here. If a link keeps failing, or you signed in with InfoDot using the same e-mail address, your orders are under <a href="{{ route('orders.index') }}">your account</a> instead.</p>
    </div>
</x-store-layout>
