@php
    use App\Services\Notifications\NotificationPreferences as P;
@endphp
<x-account-layout>
    <x-slot:title>Notification choices</x-slot:title>
    <x-slot:heading>Notification choices</x-slot:heading>
    <x-slot:lead>What we tell you, and where. Messages about your orders cannot be switched off.</x-slot:lead>

    <form method="POST" action="{{ route('account.notifications.update') }}" class="co-form">
        @csrf @method('PUT')

        <x-ds.panel title="Orders, payments, delivery and returns">
            <p>Always on, in the app and by e-mail. These are the messages that tell you your money, your parcel or your return needs attention, so they cannot be turned off.</p>
            <div class="co-toggles">
                <x-ds.toggle label="In the app" name="transactional_in_app" :checked="true" disabled />
                <x-ds.toggle label="By e-mail" name="transactional_mail" :checked="true" disabled />
            </div>
        </x-ds.panel>

        <x-ds.panel title="Alerts you asked for">
            <p>Back in stock and price drop alerts. You get one only for a product you set an alert on, once per change. Choose how.</p>
            <div class="co-toggles">
                <x-ds.toggle label="In the app" name="alerts_in_app" :checked="$prefs[P::ALERTS]['in_app']" />
                <x-ds.toggle label="By e-mail" name="alerts_mail" :checked="$prefs[P::ALERTS]['mail']" />
            </div>
        </x-ds.panel>

        <x-ds.panel title="News and offers">
            <p>Off until you say yes. We send no marketing without your say-so.</p>
            <div class="co-toggles">
                <x-ds.toggle label="In the app" name="marketing_in_app" :checked="$prefs[P::MARKETING]['in_app']" />
                <x-ds.toggle label="By e-mail" name="marketing_mail" :checked="$prefs[P::MARKETING]['mail']" />
            </div>
        </x-ds.panel>

        <div class="co-stepact"><x-ds.button type="submit" icon="check">Save choices</x-ds.button></div>
    </form>
</x-account-layout>
