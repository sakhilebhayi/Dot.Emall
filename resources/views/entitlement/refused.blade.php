{{--
  R-50: the company does not hold Dot.Emall. The error label from errors/rich.blade.php (ds-label inside ds-err) with the verdict where the error code sits and ONE
  primary action (data-primary-action). Rendered by App\Support\EntitlementRefusal with HTTP 403. Shows nothing from the person's store, orders or account.
  Variables: $stamp, $title, $body, $platformsUrl, $otherCompanies (a collection of teams the person can switch to).
--}}
<x-store-layout robots="noindex" :contained="true">
    <x-slot:title>{{ $title }}</x-slot:title>
    <section class="ds-err" aria-labelledby="gate-h">
        <div class="ds-label ds-err__label">
            <div class="ds-label__main">
                <span class="ds-label__from">{{ $stamp }}</span>
                <h1 id="gate-h">{{ $title }}</h1>
            </div>
            <div class="ds-label__perf" aria-hidden="true"></div>
            <div class="ds-auth__body">
                <p>{{ $body }}</p>
                <div class="ds-page-header__actions">
                    <x-ds.button :href="$platformsUrl" iconRight="arrow-right" data-primary-action>Open Platforms in InfoDot</x-ds.button>
                </div>
            </div>
        </div>

        <div class="ds-err__find">
            @if ($otherCompanies->isNotEmpty())
                <h2>Switch to {{ $otherCompanies->count() === 1 ? 'your other workspace' : 'another workspace' }}</h2>
                <ul class="ds-entitle-others">
                    @foreach ($otherCompanies as $other)
                        <li>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $other->id }}">
                                <b>{{ $other->name }}</b>
                                <x-ds.button type="submit" variant="quiet" size="sm" aria-label="Switch to {{ $other->name }}">Switch</x-ds.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <h2>The shop is still open</h2>
            <p class="ds-muted">Browsing, the cart and checkout need no company, and orders you have already placed keep working. <a href="{{ url('/') }}">Back to the shop</a>@auth, or <a href="{{ route('orders.index') }}">see your orders</a>@endauth.</p>
        </div>
    </section>
</x-store-layout>
