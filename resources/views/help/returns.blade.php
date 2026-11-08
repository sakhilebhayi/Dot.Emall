<x-store-layout>
    <x-slot:title>Returns and refunds</x-slot:title>
    <x-slot:description>How to return an item, who pays, how long the seller has to answer, and how refunds work on Dot.Emall.</x-slot:description>
    <x-slot:heading>Returns and refunds</x-slot:heading>
    <x-slot:lead>You can ask to return an item within {{ $days }} days of delivery.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <h2>The rule</h2>
        <p>You may ask for a return of any item within {{ $days }} days of it being delivered or collected. We apply the {{ $days }}-day period that South African law (ECTA section 44) sets for online sales to every order as our standard. This page describes our process and is not legal advice.</p>

        <h2>How to ask</h2>
        <ol class="co-steps-list">
            <li>Open the order and choose Return on the item. A guest uses the link in their order e-mail.</li>
            <li>Choose a reason and tell the seller what is wrong. You can add up to {{ (int) config('emall.returns.max_photos', 4) }} photos.</li>
            <li>The seller approves or declines. A decline must come with a reason, and you can read it on the return.</li>
            <li>If approved, send the item back as the seller explains. When the seller confirms it arrived, the refund is recorded and sent through Paystack.</li>
        </ol>

        <h2>Who pays for the return</h2>
        <ul>
            <li><strong>The seller pays</strong> when the item is faulty or damaged, not as described, or the wrong item.</li>
            <li><strong>You pay</strong> when you changed your mind.</li>
        </ul>

        <h2>What is refunded</h2>
        <p>The price you paid for the item, after any promo discount that applied to it. Delivery fees are not refunded by a return. A refund shows on the order as recorded, then as processing at Paystack, then as refunded once Paystack confirms. It can take several business days to reach your account after that.</p>

        <h2>If the seller does not answer</h2>
        <p>A seller has {{ $respondDays }} days to answer. After that the return goes to the Emall team's queue automatically. If a seller declines and you disagree, you can send it to the same queue yourself, with a note. The Emall team reads both sides and decides.</p>

        <h2>Cancelling before it ships</h2>
        <p>That is not a return: cancel the item from the order page until the seller has sent it.</p>
    </div>
</x-store-layout>
