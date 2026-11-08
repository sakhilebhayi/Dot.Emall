<x-store-layout>
    <x-slot:title>Sell on Emall</x-slot:title>
    <x-slot:description>What selling on Dot.Emall involves: the fees, returns, shipping and payouts, written down before you start.</x-slot:description>
    <x-slot:heading>Sell on Emall</x-slot:heading>
    <x-slot:lead>A storefront for your business, with every cost and rule written down before you begin.</x-slot:lead>

    <div class="sd-page">
        <section aria-labelledby="start-door" class="sd-cols">
            <div class="sd-stack">
                <h2 id="start-door" class="sd-h2">
                    @if ($store) You already have a store @elseif ($user) Open your store @else Start by signing in with InfoDot @endif
                </h2>
                @if ($store)
                    <p class="sd-muted sd-measure">{{ $store->name }} is on Emall. Pick up where you left off.</p>
                    <div class="sd-actions">
                        <x-ds.button variant="primary" size="lg" :href="route('seller.dashboard')" iconRight="arrow-right">Go to your seller desk</x-ds.button>
                        <x-ds.button variant="quiet" size="lg" :href="route('seller.store.status')">Store status and checklist</x-ds.button>
                    </div>
                @elseif ($user)
                    <p class="sd-muted sd-measure">You are signed in as {{ $user->name }}. Opening a store takes one short form, then a checklist you can finish in your own time.</p>
                    <div class="sd-actions"><x-ds.button variant="primary" size="lg" :href="route('seller.store.create')" iconRight="arrow-right">Open your store</x-ds.button></div>
                @else
                    <p class="sd-muted sd-measure">Emall has no sign-up form of its own. Accounts on Emall come from InfoDot, so sign in there and, if you are new, your Emall account is made on the way in. That keeps one business identity behind every store, and it is what lets a shopper see who they are buying from.</p>
                    <div class="sd-actions">
                        <x-ds.button variant="primary" size="lg" :href="route('login')" iconRight="arrow-right">Sign in with InfoDot</x-ds.button>
                    </div>
                    <p class="sd-quiet">After you sign in you come straight back to the first step.</p>
                @endif
            </div>
            <aside class="sd-stack" aria-label="Before you start">
                <h2 class="sd-h3">Have these to hand</h2>
                <ul class="sd-muted sd-bullets">
                    <li>A name and a few sentences about your store.</li>
                    <li>The province you ship from.</li>
                    <li>A contact e-mail address.</li>
                    <li>What you charge for delivery, and how long you need to pack.</li>
                </ul>
                <p class="sd-quiet">Company registration and VAT numbers are optional. If you add them they are shown as claimed by you.</p>
            </aside>
        </section>

        <section aria-labelledby="start-how">
            <h2 id="start-how" class="sd-h2" style="margin-block-end: var(--s-4);">What selling involves</h2>
            <ol class="sd-how">
                <li>
                    <div><h3>Set up your store</h3><p>Name, description, logo, the province you ship from, your shipping and returns policies and at least one shipping method.
                    @if ($requiresReview) The Emall team reviews a new store before it opens to shoppers and tells you why if it needs changes. @else Your store opens as soon as you create it. @endif</p></div>
                </li>
                <li><div><h3>List your products</h3><p>Add photos, a price, stock, options such as size and colour, and the specifications your category asks for. The Emall team checks each listing before it appears, and if one is turned down you see the reason and can send it again.</p></div></li>
                <li><div><h3>Pack and ship</h3><p>When a buyer pays, the parcel shows up under To ship. You pack it, choose a carrier, add a tracking number and the buyer is told. You cannot ship an order that is still waiting for payment.</p></div></li>
                <li><div><h3>Get paid</h3><p>Your earnings go into your ledger when the buyer pays and are released {{ $releaseDays }} days after the parcel is delivered. The terms below say exactly how.</p></div></li>
            </ol>
        </section>

        <section aria-labelledby="start-terms">
            <h2 id="start-terms" class="sd-h2" style="margin-block-end: var(--s-4);">The terms, in plain words</h2>
            <div class="sd-terms">
                <div><h3>Fees</h3><p>{{ $feeSentence }}</p></div>
                <div><h3>Payouts</h3><p>Earnings are recorded in your Emall ledger when the buyer pays and are released {{ $releaseDays }} days after delivery, unless a return on that parcel is still open. Payouts are arranged with the Emall team for now: there are no automatic payouts yet. Your ledger page shows what is held and what is released.</p></div>
                <div><h3>Returns</h3><p>A buyer can ask to return an item within {{ $returnDays }} days of it arriving. For a fault, a wrong item or an item that was not as described you pay for the return; for a change of mind the buyer does. You answer within {{ $responseDays }} days and give a reason if you decline; if you do not answer, it goes to the Emall team. When you mark the goods received the buyer is refunded and your ledger is adjusted.</p></div>
                <div><h3>Shipping</h3><p>You set your own methods and prices: a flat rate, free over an amount, or collection. The delivery dates a buyer sees are estimates worked out from your packing time and the method you choose, in working days, and they are labelled as estimates. Tracking details are whatever you enter, and the buyer sees them marked as coming from you.</p></div>
                <div><h3>What shoppers see about you</h3><p>Your store name, logo and description, your policies, your province, and a few facts Emall measures from real orders such as how often you ship within your packing time. Those facts only appear once you have enough orders for them to mean something. Numbers you type yourself, such as a registration or VAT number, are labelled as claimed.</p></div>
                <div><h3>Privacy</h3><p>You see a buyer's delivery address only while there is something left to ship. After that it is hidden, and e-mail addresses are not shown to sellers at all.</p></div>
            </div>
        </section>
    </div>
</x-store-layout>
