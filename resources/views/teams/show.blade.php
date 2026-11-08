<x-account-layout>
    <x-slot:title>Team settings</x-slot:title>
    <x-slot:heading>Team settings</x-slot:heading>
    <div>
        <div>
            @livewire('teams.update-team-name-form', ['team' => $team])

            @livewire('teams.team-member-manager', ['team' => $team])

            @if (Gate::check('delete', $team) && ! $team->personal_team)
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('teams.delete-team-form', ['team' => $team])
                </div>
            @endif
        </div>
    </div>
</x-account-layout>
