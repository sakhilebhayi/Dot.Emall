<x-store-layout>
    <x-slot:title>Contact</x-slot:title>
    <x-slot:description>How to reach a seller or the Dot.Emall team about an order.</x-slot:description>
    <x-slot:heading>Contact</x-slot:heading>
    <x-slot:lead>Start from your order. It already knows who sold what, and when.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <h2>About an order</h2>
        <p>Open the order and use Contact the seller. Your message goes to the people who run that store, with the order number attached. You can also use Report a problem on the same page. That goes to the Emall team's queue, and they can see the order.</p>
        @auth
            <p><x-ds.button :href="route('orders.index')" variant="quiet" icon="receipt">Go to your orders</x-ds.button></p>
        @else
            <p>Bought as a guest? Use the link in your order e-mail. Signed-in orders are under <a href="{{ route('login') }}">your account</a> (accounts come from InfoDot).</p>
        @endauth

        <h2>Everything else</h2>
        @if ($email !== '')
            <p>Write to <a href="mailto:{{ $email }}">{{ $email }}</a> and include your order number if you have one.</p>
        @else
            <p>There is no general support e-mail address or phone number published yet, and we will not make one up. For now, contact is through the order page as described above. Questions about a product go to the seller from the product page.</p>
        @endif

        <h2>Payment problems</h2>
        <p>If you paid and the order still says awaiting payment, press Check payment again on the order page. We ask Paystack directly. Please do not pay a second time. If Paystack has confirmed a payment we cannot match, the order page says so and the Emall team has it in their queue.</p>
    </div>
</x-store-layout>
