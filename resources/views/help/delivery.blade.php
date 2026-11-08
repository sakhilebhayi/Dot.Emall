<x-store-layout>
    <x-slot:title>Delivery</x-slot:title>
    <x-slot:description>Who sets delivery prices and dates, how estimates are worked out, and how tracking works on Dot.Emall.</x-slot:description>
    <x-slot:heading>Delivery</x-slot:heading>
    <x-slot:lead>Every seller sends their own parcel and sets their own delivery. Here is what that means for you.</x-slot:lead>
    @include('help._nav')

    <div class="co-prose">
        <h2>One parcel per seller</h2>
        <p>If your cart holds items from three sellers, you receive up to three parcels. Each has its own delivery price, method and arrival estimate, and they can arrive on different days. The cart and checkout show every delivery price and the total before you pay.</p>

        <h2>How the estimate is worked out</h2>
        <p>An arrival date is the seller's handling time plus the delivery method's window, counted in working days (Monday to Friday). Public holidays are not counted out, so a date can be a day or two late around a holiday. It is an estimate set by the seller, not a promise, and it is labelled that way wherever you see it.</p>

        <h2>Delivery methods</h2>
        <ul>
            <li><strong>Flat rate:</strong> one price to the places the seller delivers to.</li>
            <li><strong>Free over an amount:</strong> delivery is free once you spend that much with the same seller. The cart tells you how much more you need.</li>
            <li><strong>Collection:</strong> you collect from the seller's address, shown before you choose it. Collection orders go from packing to ready for collection, then collected.</li>
            <li><strong>Arranged with the seller:</strong> a seller who has set up no method still sells. Delivery is then arranged with them, costs nothing extra on our side and has no date. Message them from the order page.</li>
        </ul>
        <p>Some methods apply only in certain provinces or postal code ranges. If a seller does not deliver to your address, checkout says so before you pay.</p>

        <h2>Tracking</h2>
        <p>Dot.Emall does not connect to courier systems. The carrier, tracking number and progress notes on your order are entered by the seller and are marked "from the seller". A tracking link appears only for carriers we recognise and only when the number matches that carrier's format. Otherwise the number is shown as plain text.</p>

        <h2>When you have it</h2>
        <p>The seller marks a parcel delivered, or you can confirm you have it from the order page. Your return period starts on that day.</p>
    </div>
</x-store-layout>
