@php
    // Invented specimens, never saved. They exist only so the gallery has something to draw.
    $photo = fn (string $n) => asset('images/'.$n);
    $imgs = [['src' => asset('images/emall-mark.png'), 'alt' => 'Specimen photo 1'], ['src' => asset('images/emall-wordmark.png'), 'alt' => 'Specimen photo 2'], ['src' => asset('images/emall-mark.png'), 'alt' => 'Specimen photo 3']];
    $palette = [
        'Grounds' => ['--ground' => 'Label paper', '--surface' => 'Shelf', '--raised' => 'Raised', '--sunken' => 'Crate', '--photo-ground' => 'Photo backdrop'],
        'Ink' => ['--ink' => 'Marker ink', '--ink-2' => 'Ink 2', '--ink-3' => 'Ink 3', '--line' => 'Line', '--line-strong' => 'Line strong'],
        'Colour' => ['--brand' => 'Stamp indigo', '--brand-hover' => 'Stamp hover', '--brand-soft' => 'Stamp soft', '--awning' => 'Awning yellow'],
        'Tones' => ['--pos' => 'Fresh', '--pos-bg' => 'Fresh ground', '--cau' => 'Caution', '--cau-bg' => 'Caution ground', '--crit' => 'Brick', '--crit-bg' => 'Brick ground'],
    ];
    $orderStates = array_keys(\App\Support\Status::ORDER);
    $productStates = array_keys(\App\Support\Status::PRODUCT);
@endphp
<x-store-layout :contained="false">
    <x-slot:title>Design system kit</x-slot:title>
    <x-slot:description>Every Dot.Emall component, in light and dark. Local and testing only.</x-slot:description>
    <x-slot:testmode><x-ds.badge tone="cau" icon="alert-triangle">Kit gallery, not part of the shop</x-ds.badge></x-slot:testmode>

    <div class="ds-container kit">
        <x-ds.page-header title="Shelf Edge, the Dot.Emall kit" lead="Each block below is drawn twice: once in the light theme and once in the dark, side by side, from the same tokens." />

        <h2 id="k-colour">Colour</h2>
        <div class="kit__both">
            @foreach (['is-light' => 'Light', 'is-dark' => 'Dark'] as $cls => $name)
                <div class="kit__theme {{ $cls }}">
                    <h3 style="margin-top: 0;">{{ $name }}</h3>
                    @foreach ($palette as $group => $tokens)
                        <p class="ds-muted" style="margin: var(--s-3) 0 var(--s-2); font-size: var(--text-sm);">{{ $group }}</p>
                        <div class="kit__swatches">@foreach ($tokens as $token => $label)<div class="kit__swatch"><div class="kit__chip" style="--chip: var({{ $token }});"></div><span>{{ $label }}</span><code>{{ ltrim($token, "-") }}</code></div>@endforeach</div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <h2 id="k-type">Type</h2>
        <div class="kit__type">
            <p style="font-size: var(--text-3xl); font-weight: 750; font-stretch: var(--width-head); line-height: 1.1; letter-spacing: -0.02em;">Display, Schibsted Grotesk 750</p>
            <h2 style="border: 0; margin: 0; padding: 0; font-size: var(--text-xl);">A heading for a section</h2>
            <h3 style="margin: 0;">A smaller heading for a group</h3>
            <p>Body text is sixteen pixels at a 1.55 line height, in Schibsted Grotesk. It stays in sentence case and says plainly what is on offer, what it costs and when it arrives.</p>
            <p class="ds-muted">Quiet text is for hints and notes. It still passes AA on every ground.</p>
            <p>Money in tabular numerals: <x-ds.price :cents="74900" size="xl" /> <x-ds.price :cents="1299900" size="lg" /> <x-ds.price :cents="5" size="md" /> <x-ds.price :cents="100000" size="md" /></p>
            <p>Codes in Martian Mono: <span class="ds-code">EM-2025-004172</span> <span class="ds-code">1Z 999 AA1 01 2345 6784</span></p>
        </div>

        <div class="kit__both" style="margin-top: var(--s-6);">
        @foreach (['is-light', 'is-dark'] as $cls)
        <div class="kit__theme {{ $cls }}">
            <h2 style="margin-top: 0;">Buttons and icons</h2>
            <div class="kit__row">
                <x-ds.button>Add to cart</x-ds.button><x-ds.button variant="quiet">Save for later</x-ds.button><x-ds.button variant="danger">Remove</x-ds.button><x-ds.button variant="link">Read the policy</x-ds.button>
                <x-ds.button size="sm">Small</x-ds.button><x-ds.button size="lg" icon="cart">Large</x-ds.button><x-ds.button :loading="true">Saving</x-ds.button><x-ds.button :disabled="true">Disabled</x-ds.button>
            </div>
            <div class="kit__row" style="margin-top: var(--s-3);">
                <x-ds.icon-button icon="heart" label="Add to wishlist" :pressed="false" /><x-ds.icon-button icon="heart" label="Remove from wishlist" :pressed="true" /><x-ds.icon-button icon="search" label="Search" /><x-ds.theme-toggle />
            </div>
            <div class="kit__row" style="margin-top: var(--s-3); color: var(--ink-2);">
                @foreach (\App\Support\Icons::names() as $n)<span title="{{ $n }}"><x-ds.icon :name="$n" :size="22" /></span>@endforeach
            </div>

            <h2>Status vocabulary</h2>
            <p class="ds-muted">Order</p><div class="kit__row">@foreach ($orderStates as $s)<x-ds.status kind="order" :state="$s" />@endforeach</div>
            <p class="ds-muted">Product</p><div class="kit__row">@foreach ($productStates as $s)<x-ds.status kind="product" :state="$s" :quantity="3" />@endforeach</div>
            <div class="kit__row" style="margin-top: var(--s-3);"><x-ds.badge>Neutral</x-ds.badge><x-ds.badge tone="info">Info</x-ds.badge><x-ds.badge tone="ink">Ink</x-ds.badge><span class="ds-count">3</span></div>

            <h2>Price and the price label</h2>
            <div class="kit__row"><x-ds.price :cents="74900" size="lg" :unit="'R28.81 per 100 g'" :vat-included="true" /> <x-ds.price :cents="74900" size="lg" :previous-cents="89900" /></div>
            <div class="kit__grid" style="margin-top: var(--s-4);">
                <x-ds.price-label :price-cents="74900" from="Karoo Home and Hearth" title="Cast iron skillet, 26 cm" :delivery-cents="6500" delivery-note="To Johannesburg, 2 to 4 working days" />
                <x-ds.price-label :price-cents="129900" from="Joburg Tech Hub" title="Desk lamp" />
                <x-ds.price-label :price-cents="4900" :delivery-cents="0" delivery-note="Free over R500" compact title="Compact label" />
            </div>

            <h2>Rating, avatar, skeleton</h2>
            <div class="kit__row"><x-ds.rating :value="4.6" :count="120" /><x-ds.rating :value="3" :count="1" /><x-ds.rating :value="0" :count="0" /><span class="ds-muted">(no stars at zero reviews)</span><x-ds.avatar name="Thandi Mokoena" /><x-ds.avatar name="Pieter van der Merwe" size="lg" /></div>
            <div style="display: grid; gap: var(--s-2); margin-top: var(--s-3);"><x-ds.skeleton height="1.2rem" width="60%" /><x-ds.skeleton height="1rem" /><x-ds.skeleton ratio="4 / 3" width="12rem" /></div>

            <h2>Alerts and toasts</h2>
            <div class="ds-stack" style="--stack: var(--s-2);">
                <x-ds.alert tone="info" title="Heads up">Prices include VAT where the seller charges it.</x-ds.alert>
                <x-ds.alert tone="pos">Your order was placed.</x-ds.alert>
                <x-ds.alert tone="cau" title="Check your address">The postcode looks short.</x-ds.alert>
                <x-ds.alert tone="crit" title="Payment failed" :dismissible="true">Your bank declined the payment. Nothing was charged.</x-ds.alert>
                <div class="ds-toast" role="status" style="animation: none;"><x-ds.icon name="check-circle" :size="20" /><span>Added to your cart</span></div>
            </div>

            <h2>Forms</h2>
            <form class="ds-stack" style="--stack: var(--s-4); max-width: 28rem;" action="#">
                <x-ds.input name="kit_name{{ $cls }}" label="Full name" hint="As it should appear on the parcel." required />
                <x-ds.input name="kit_email{{ $cls }}" label="Email" type="email" error="Enter an email address with an @ in it." value="thandi.example.com" />
                <x-ds.textarea name="kit_notes{{ $cls }}" label="Delivery notes" rows="2" hint="Gate codes, a neighbour, the time you are home." />
                <x-ds.select name="kit_prov{{ $cls }}" label="Province" placeholder="Choose a province" :options="['gp' => 'Gauteng', 'wc' => 'Western Cape', 'kzn' => 'KwaZulu-Natal']" />
                <x-ds.checkbox name="kit_c{{ $cls }}" label="Save this address" hint="You can edit it later in your account." checked />
                <x-ds.radio legend="Delivery" name="kit_r{{ $cls }}" :options="['std' => ['label' => 'Standard courier', 'hint' => '2 to 4 working days'], 'pickup' => ['label' => 'Collect from the seller', 'hint' => 'Free']]" selected="std" />
                <x-ds.toggle name="kit_t{{ $cls }}" label="Email me when it ships" :checked="true" />
                <div class="kit__row"><x-ds.quantity-stepper name="kit_q{{ $cls }}" :value="2" :max="5" /><x-ds.sort-select :options="['new' => 'Newest', 'low' => 'Price, low to high']" name="kit_s{{ $cls }}" /></div>
                <x-ds.option-picker name="kit_size{{ $cls }}" label="Size" selected="m" :options="[['value' => 's', 'label' => 'S'], ['value' => 'm', 'label' => 'M'], ['value' => 'l', 'label' => 'L', 'disabled' => true, 'note' => 'sold out'], ['value' => 'xl', 'label' => 'XL']]" />
                <x-ds.option-picker name="kit_col{{ $cls }}" label="Colour" type="swatch" selected="indigo" :options="[['value' => 'indigo', 'label' => 'Indigo', 'swatch' => '#4b58a8'], ['value' => 'sand', 'label' => 'Sand', 'swatch' => '#d9c9a3'], ['value' => 'moss', 'label' => 'Moss', 'swatch' => '#5f7a4a', 'disabled' => true]]" />
            </form>
        </div>
        @endforeach
        </div>

        <h2 id="k-search">Search, facets, breadcrumbs, pagination, steps</h2>
        <div class="kit__grid">
            <div class="ds-stack">
                <x-ds.search-field action="#" id="kit-search" value="skillet">
                    <x-slot:suggestions>
                        <li class="ds-search__opt" role="option" id="kit-search-o1" aria-selected="false"><a href="#" tabindex="-1"><span>Cast iron skillet</span><small>Cookware</small></a></li>
                        <li class="ds-search__opt" role="option" id="kit-search-o2" aria-selected="false"><a href="#" tabindex="-1"><span>Skillet lid</span><small>Cookware</small></a></li>
                    </x-slot:suggestions>
                </x-ds.search-field>
                <x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => '#'], ['label' => 'Kitchen', 'href' => '#'], ['label' => 'Skillets']]" />
                <x-ds.progress-steps :steps="['Delivery', 'Payment', 'Review']" :current="2" />
            </div>
            <div>
                <x-ds.facet-group title="Brand" name="kit_brand" :options="[['value' => 'a', 'label' => 'Karoo Home', 'count' => 12, 'checked' => true], ['value' => 'b', 'label' => 'Veld and Vine', 'count' => 4]]" />
                <x-ds.facet-group title="Price" name="kit_price" type="range" :min="100" :max="900" />
            </div>
        </div>

        <h2 id="k-product">Product card, gallery, option picker</h2>
        <div class="ds-grid">
            <x-ds.product-card href="#" title="Cast iron skillet, 26 cm" :image="asset('images/emall-mark.png')" store="Karoo Home and Hearth" :price-cents="74900" :previous-cents="89900" :rating-value="4.6" :rating-count="38" :stock="12" ratio="1 / 1">
                <x-slot:wishlist><x-ds.icon-button icon="heart" label="Add to wishlist" :pressed="false" /></x-slot:wishlist>
            </x-ds.product-card>
            <x-ds.product-card href="#" title="Desk lamp with a very long product name that has to wrap onto a second line" :image="asset('images/emall-mark.png')" store="Joburg Tech Hub" :price-cents="129900" :stock="3" ratio="1 / 1" />
            <x-ds.product-card href="#" title="Sold out vase" :image="asset('images/emall-mark.png')" store="Karoo Home and Hearth" :price-cents="39900" :stock="0" ratio="1 / 1" />
            <x-ds.product-card href="#" title="No photo yet" store="Ubuntu Beauty Co." :price-cents="5900" :stock="40" ratio="1 / 1" />
        </div>
        <div style="max-width: 36rem; margin-top: var(--s-5);"><x-ds.product-card variant="row" href="#" title="Row variant for phone lists" :image="asset('images/emall-mark.png')" store="Veld and Vine Outdoors" :price-cents="24900" :rating-value="4" :rating-count="9" :stock="4" /></div>
        <div style="max-width: 34rem; margin-top: var(--s-5);"><x-ds.product-gallery :images="$imgs" ratio="4 / 3" /></div>

        <h2 id="k-trust">Seller trust, delivery, cart line, waybill, address, review</h2>
        <div class="kit__grid">
            <x-ds.seller-trust :rungs="[
                ['label' => 'Signed in through InfoDot', 'how' => 'Checked by InfoDot at sign-in', 'state' => 'checked'],
                ['label' => 'Company linked in InfoDot', 'how' => 'Not linked', 'state' => 'missing'],
                ['label' => 'VAT number', 'how' => 'Typed by the seller, not checked', 'state' => 'claimed', 'value' => '4123456789'],
                ['label' => 'Shipped within the promise', 'how' => 'Measured on 148 orders', 'state' => 'metric', 'value' => '91%'],
            ]" />
            <div class="ds-stack">
                <x-ds.delivery-estimate range="2 to 4 working days" to="Johannesburg 2196" :cost-cents="6500" method="Courier" />
                <x-ds.address-block name="Thandi Mokoena" :lines="['12 Jacaranda Road', 'Rosebank', 'Johannesburg 2196']" phone="082 555 0101" :is-default="true" :card="true"><x-slot:actions><x-ds.button size="sm" variant="quiet">Edit</x-ds.button><x-ds.button size="sm" variant="link">Delete</x-ds.button></x-slot:actions></x-ds.address-block>
            </div>
        </div>
        <div style="max-width: 44rem; margin-top: var(--s-4);">
            <x-ds.cart-line title="Cast iron skillet, 26 cm" href="#" store="Karoo Home and Hearth" option="Size 26 cm" :unit-cents="74900" :quantity="2" :line-cents="149800" :image="asset('images/emall-mark.png')" note="Only 3 left">
                <x-ds.quantity-stepper name="kit_cl" :value="2" :max="3" />
                <x-ds.button size="sm" variant="link">Remove</x-ds.button>
            </x-ds.cart-line>
        </div>
        <div class="kit__grid" style="margin-top: var(--s-5); grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));">
            <x-ds.order-timeline code="EM-004172" tracking="ZA 7719 3304" carrier="Courier Guy" :steps="[
                ['key' => 'awaiting_payment', 'label' => 'Order placed', 'at' => '12 Oct 2025, 09:14', 'state' => 'done'],
                ['key' => 'paid', 'label' => 'Paid', 'at' => '12 Oct 2025, 09:16', 'state' => 'done'],
                ['key' => 'packing', 'label' => 'Packing', 'at' => '12 Oct 2025, 14:02', 'state' => 'done', 'note' => 'From the seller: packed with the lid separate.'],
                ['key' => 'shipped', 'label' => 'Shipped', 'at' => '13 Oct 2025, 08:40', 'state' => 'current'],
                ['key' => 'delivered', 'label' => 'Delivered', 'at' => null, 'state' => 'todo'],
            ]" />
            <x-ds.order-timeline :steps="[
                ['key' => 'awaiting_payment', 'label' => 'Order placed', 'at' => '3 Nov 2025, 10:00', 'state' => 'done'],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'at' => '3 Nov 2025, 10:30', 'state' => 'stop', 'note' => 'Cancelled before payment.'],
            ]" />
            <div>
                <x-ds.review-card author="Thandi M." :rating="5" title="Heavy and well made" body="Seasoned it once and it has not stuck since. The handle stays cool enough to lift with a cloth." date="4 Nov 2025" :verified="true" :reply="['from' => 'Karoo Home and Hearth', 'body' => 'Thank you. Wash by hand and dry on the stove for the best finish.', 'date' => '5 Nov 2025']" />
                <x-ds.review-card author="A buyer" :rating="3" body="Arrived a day late." date="2 Nov 2025" />
            </div>
        </div>

        <h2 id="k-ui">Dialog, dropdown, tabs, table, empty state, stat, definition list</h2>
        <div class="kit__row">
            <x-ds.button variant="quiet" data-dialog-open="kit-dialog">Open a dialog</x-ds.button>
            <x-ds.button variant="danger" data-dialog-open="kit-confirm">Delete a product</x-ds.button>
            <x-ds.dropdown label="Actions" icon="sliders" id="kit-dd"><x-ds.dropdown-item href="#" icon="edit">Edit</x-ds.dropdown-item><x-ds.dropdown-item href="#" icon="copy">Duplicate</x-ds.dropdown-item><hr class="ds-dropdown__sep"><x-ds.dropdown-item type="button" icon="trash" :danger="true">Delete</x-ds.dropdown-item></x-ds.dropdown>
        </div>
        <x-ds.dialog id="kit-dialog" title="Choose a delivery address"><p>Pick where this order should go. You can add another address in your account.</p><x-slot:footer><x-ds.button variant="quiet" data-dialog-close>Cancel</x-ds.button><x-ds.button data-dialog-close>Use this address</x-ds.button></x-slot:footer></x-ds.dialog>
        <x-ds.confirm-dialog id="kit-confirm" title="Delete this product?" action="#" method="DELETE" confirm-label="Delete product"><p>The listing and its photos will be removed. This cannot be undone.</p></x-ds.confirm-dialog>

        <div style="margin-top: var(--s-5);">
            <x-ds.tabs :tabs="['details' => 'Details', 'specs' => 'Specifications', 'reviews' => 'Reviews']" active="details">
                <x-ds.tab-panel id="details"><p>Details panel. Arrow keys move between tabs.</p></x-ds.tab-panel>
                <x-ds.tab-panel id="specs"><x-ds.dl><dt>Material</dt><dd>Cast iron</dd><dt>Diameter</dt><dd>26 cm</dd><dt>Weight</dt><dd>2.4 kg</dd></x-ds.dl></x-ds.tab-panel>
                <x-ds.tab-panel id="reviews"><p>Reviews panel.</p></x-ds.tab-panel>
            </x-ds.tabs>
        </div>

        <div style="margin-top: var(--s-5);">
            <x-ds.table caption="Orders to fulfil">
                <x-slot:head><tr><th scope="col">Order</th><th scope="col">Buyer</th><th scope="col" class="is-num">Total</th><th scope="col">Status</th></tr></x-slot:head>
                <tr><td data-label="Order"><span class="ds-code">EM-004172</span></td><td data-label="Buyer">Thandi M.</td><td data-label="Total" class="is-num"><x-ds.price :cents="149800" size="sm" /></td><td data-label="Status"><x-ds.status kind="order" state="shipped" /></td></tr>
                <tr><td data-label="Order"><span class="ds-code">EM-004171</span></td><td data-label="Buyer">Pieter vdM.</td><td data-label="Total" class="is-num"><x-ds.price :cents="39900" size="sm" /></td><td data-label="Status"><x-ds.status kind="order" state="awaiting_payment" /></td></tr>
            </x-ds.table>
        </div>

        <div class="kit__grid" style="margin-top: var(--s-5);">
            <x-ds.stat label="Paid this month" value="R 18,420.00" note="From 23 orders" />
            <x-ds.empty-state title="No returns yet" icon="return">When a buyer asks to return something, it shows here with the days left to answer.<x-slot:actions><x-ds.button variant="quiet">See how returns work</x-ds.button></x-slot:actions></x-ds.empty-state>
            <x-ds.panel title="A panel"><p>Flat, in the column, with a hairline edge.</p></x-ds.panel>
        </div>
    </div>
</x-store-layout>
