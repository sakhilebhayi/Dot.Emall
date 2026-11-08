<x-store-layout>
    <x-slot:title>Open your store</x-slot:title>
    <x-slot:heading>Open your store</x-slot:heading>
    <x-slot:lead>Three minutes now: who you are and where you ship from. A checklist then takes you through the rest.</x-slot:lead>

    <x-seller.errors />
    <form method="POST" action="{{ route('seller.store.store') }}" class="sd-form" novalidate>
        @csrf
        <section class="sd-form__section" aria-labelledby="c-identity">
            <div class="sd-form__intro"><h2 id="c-identity">Your store</h2><p>This is what shoppers see at the top of your store page.</p></div>
            <div class="sd-fields">
                <x-ds.input label="Store name" name="name" :required="true" hint="You can change it later. Your store address stays the same." autocomplete="organization" maxlength="255" />
                <x-ds.textarea label="About your store" name="description" rows="4" hint="A few sentences on what you sell and who you are. Up to 2,000 characters." maxlength="2000" />
            </div>
        </section>
        <section class="sd-form__section" aria-labelledby="c-contact">
            <div class="sd-form__intro"><h2 id="c-contact">Contact and location</h2><p>Shoppers see the province and city, never your street address. The Emall team uses the e-mail to reach you about your store.</p></div>
            <div class="sd-fields sd-fields--two">
                <div class="sd-span"><x-ds.input label="Contact e-mail" name="contact_email" type="email" :value="old('contact_email', $email)" autocomplete="email" /></div>
                <x-ds.select label="Province you ship from" name="region" :options="array_combine($provinces, $provinces)" placeholder="Choose a province" />
                <x-ds.input label="City or town" name="city" autocomplete="address-level2" maxlength="100" />
            </div>
        </section>
        <div class="sd-sticky-actions sd-actions">
            <x-ds.button type="submit" variant="primary" size="lg">Create my store</x-ds.button>
            <a class="ds-btn ds-btn--link" href="{{ route('sell.start') }}">What does selling involve?</a>
        </div>
    </form>
</x-store-layout>
