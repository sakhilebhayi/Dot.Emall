{{-- Team entries for the account menus (Jetstream teams): settings, create, switch. Variable: $user. Renders nothing when teams are off or the user has no current team. --}}
@if (\Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->currentTeam)
    <hr class="ds-dropdown__sep">
    <x-ds.dropdown-item :href="route('teams.show', $user->currentTeam->id)" icon="building">Team settings</x-ds.dropdown-item>
    @can('create', \Laravel\Jetstream\Jetstream::newTeamModel())
        <x-ds.dropdown-item :href="route('teams.create')" icon="plus">Create a team</x-ds.dropdown-item>
    @endcan
    @if ($user->allTeams()->count() > 1)
        <p class="ds-dropdown__note">Switch team</p>
        @foreach ($user->allTeams() as $switchTeam)<x-switchable-team :team="$switchTeam" />@endforeach
    @endif
@endif
