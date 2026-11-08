<x-account-layout>
    <x-slot:title>Your data and privacy</x-slot:title>
    <x-slot:heading>Your data</x-slot:heading>
    <x-slot:lead>See what Dot.Emall keeps about you, take a copy, or close your account.</x-slot:lead>

    <x-ds.panel title="Download your data">
        <p>A file with your profile, addresses, orders, returns, wishlist, saved searches, alerts and notification choices. It contains only your own information.</p>
        <p><x-ds.button :href="route('account.privacy.export')" icon="upload">Download as JSON</x-ds.button></p>
    </x-ds.panel>

    <x-ds.panel title="What we keep, and why">
        <ul class="co-list">
            <li>Your orders, returns and payments are kept while your account exists, so you and the sellers can follow them.</li>
            <li>Your sign-in comes from InfoDot. Your InfoDot account is managed there, not here.</li>
            <li>What you looked at and searched for is kept under a code, not your name, and deleted after 90 days.</li>
            <li>Read the <a href="{{ route('policy.show') }}">privacy policy</a> and <a href="{{ route('cookies') }}">cookie policy</a>.</li>
        </ul>
    </x-ds.panel>

    <x-ds.panel title="Delete your account" id="delete">
        @if ($blockers)
            <x-ds.alert tone="cau" title="You cannot delete your account yet">
                <ul class="co-list">@foreach ($blockers as $reason)<li>{{ $reason }}</li>@endforeach</ul>
            </x-ds.alert>
            <x-ds.button variant="danger" disabled>Delete my account</x-ds.button>
        @else
            <p>Deleting removes your name, e-mail, photo, addresses, wishlist, saved searches and alerts from your account, and signs you out everywhere. Your past orders, returns and payment records stay, with the delivery details you gave at the time, because the sellers and Emall need them to account for the money; your account on them becomes "Former customer", and so do your reviews. Nobody can ask for a return on those orders afterwards. Download your data first. It cannot be undone, and it asks for your password on the profile page. Your InfoDot account is not affected: that is managed at InfoDot.</p>
            <x-ds.button :href="route('profile.show').'#delete'" variant="danger" icon="trash">Go to account deletion</x-ds.button>
        @endif
    </x-ds.panel>
</x-account-layout>
