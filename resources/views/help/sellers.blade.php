<x-store-layout>
    <x-slot:title>Selling on Emall</x-slot:title>
    <x-slot:description>What selling on Dot.Emall involves: signing in, listing, delivery, fees and when you are paid.</x-slot:description>
    <x-slot:heading>Selling on Emall</x-slot:heading>
    <x-slot:lead>What to expect, stated as it works today.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <h2>Getting started</h2>
        <p>Sign in with InfoDot, then open a store from the seller area. Sign-up is closed: accounts come from InfoDot. You add listings with photos, a price and stock. Listings are reviewed before shoppers see them.</p>

        <h2>Delivery you set</h2>
        <p>You choose your delivery methods (flat rate, free over an amount, collection), where they apply, your handling time and each method's window. Shoppers see an estimate built from those numbers and labelled as yours. You enter the carrier and tracking number when you send a parcel, and what you enter is shown to the buyer as coming from you.</p>

        <h2>Fees and money</h2>
        <p>{{ $fees }}</p>

        <h2>Returns</h2>
        <p>A buyer can ask to return an item within {{ (int) config('emall.returns.window_days', 7) }} days of delivery. You answer within {{ (int) config('emall.returns.seller_response_days', 10) }} days. A decline needs a reason. After that the return goes to the Emall team.</p>

        <p><x-ds.button :href="emall_route('sell.start')" icon="store">Open a store</x-ds.button></p>
    </div>
</x-store-layout>
