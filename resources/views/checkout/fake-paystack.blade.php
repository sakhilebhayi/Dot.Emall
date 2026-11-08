<x-store-layout>
    <x-slot:title>Test payment page</x-slot:title>
    <x-slot:heading>Test payment page</x-slot:heading>
    <x-slot:lead>This stands in for Paystack's hosted page. It exists only in local and testing environments, and nothing here moves money.</x-slot:lead>
    <x-ds.panel title="Pay {{ \App\Support\Shop::money((int) $state['amount'], $state['currency']) }}">
        <p class="ds-muted">Reference <span class="ds-code">{{ $reference }}</span> for {{ $state['email'] }}.</p>
        <form method="POST" action="{{ route('dev.fake-paystack.complete', ['reference' => $reference]) }}" class="co-fakepay">
            @csrf
            <x-ds.button type="submit" name="outcome" value="success">Pay now</x-ds.button>
            <x-ds.button type="submit" name="outcome" value="failed" variant="quiet">Card declined</x-ds.button>
            <x-ds.button type="submit" name="outcome" value="abandoned" variant="quiet">Close without paying</x-ds.button>
        </form>
    </x-ds.panel>
</x-store-layout>
