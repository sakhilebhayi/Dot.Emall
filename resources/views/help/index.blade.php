<x-store-layout>
    <x-slot:title>Help centre</x-slot:title>
    <x-slot:description>How buying, delivery, payment and returns work on Dot.Emall, in plain words.</x-slot:description>
    <x-slot:heading>Help centre</x-slot:heading>
    <x-slot:lead>What happens when you buy here, what it costs, and what to do when something goes wrong.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <h2>How buying works</h2>
        <ol class="co-steps-list">
            <li><strong>Add to your cart.</strong> You do not need an account. Your cart waits in this browser.</li>
            <li><strong>Check out.</strong> Enter an e-mail address and where to deliver. Each seller shows their own delivery price and an estimated arrival. You see the total before you pay.</li>
            <li><strong>Pay on Paystack.</strong> Payments are taken by Paystack, not by us. Dot.Emall never sees your card details. An order counts as paid only when Paystack confirms it to our server, never because a page says so.</li>
            <li><strong>Follow your order.</strong> Each seller packs and sends their own parcel and enters the carrier and tracking number themselves. What you see on your order is what they entered, and it is marked that way.</li>
        </ol>

        <h2>Accounts</h2>
        <p>Accounts come from InfoDot. You can buy as a guest with just an e-mail address. If you later sign in through InfoDot with the same verified e-mail, your guest orders move into your account.</p>

        <h2>Common questions</h2>
        <dl class="co-faq">
            <dt>Where is my order?</dt>
            <dd>Open the order from your account, or from the link in your confirmation e-mail. Each parcel shows its status, the carrier and tracking number the seller entered, and an estimated arrival. If a step has no date it says "Not yet". <a href="{{ route('help.delivery') }}">More about delivery</a>.</dd>
            <dt>I paid but the order says awaiting payment.</dt>
            <dd>Open the order and press Check payment again. We ask Paystack directly. If Paystack confirms it, the order updates. If it does not, please do not pay twice: <a href="{{ route('help.contact') }}">contact us</a> with the order number.</dd>
            <dt>Something arrived wrong or damaged.</dt>
            <dd>Ask for a return from the order within {{ (int) config('emall.returns.window_days', 7) }} days of delivery. <a href="{{ route('help.returns') }}">How returns work</a>.</dd>
            <dt>Can I cancel?</dt>
            <dd>You can cancel an item until the seller has sent it. Open the order and choose Cancel item. Items go back into stock and, if you had paid, the refund is recorded and sent through Paystack.</dd>
        </dl>
    </div>
</x-store-layout>
