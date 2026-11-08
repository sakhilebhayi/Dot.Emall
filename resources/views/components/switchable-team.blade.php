@props(['team', 'component' => 'dropdown-link'])

<form method="POST" action="{{ route('current-team.update') }}" x-data>
    @method('PUT')
    @csrf
    <input type="hidden" name="team_id" value="{{ $team->id }}">
    <button type="submit" class="ds-dropdown__item" @if (Auth::user()->isCurrentTeam($team)) aria-current="true" @endif>
        @if (Auth::user()->isCurrentTeam($team))<x-ds.icon name="check" :size="18" />@endif
        <span>{{ $team->name }}</span>
    </button>
</form>
