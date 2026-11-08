{{-- Saved searches (account area). Variables: $rows (model, summary, url, new), $limit. --}}
<x-account-layout>
    <x-slot:title>Saved searches</x-slot:title>
    <x-slot:description>Searches you saved, with the new matches since you saved them.</x-slot:description>
    <x-slot:heading>Saved searches</x-slot:heading>
    <x-slot:lead>A search keeps its words and filters. We count only listings that were not in the result when you saved it or last got a digest.</x-slot:lead>

    @if ($rows->isNotEmpty())
        <ul class="disc-saved">
            @foreach ($rows as $row)
                @php($s = $row['model'])
                <li class="disc-saved__row">
                    <div class="disc-saved__main">
                        <h2><a href="{{ $row['url'] }}">{{ $s->name }}</a></h2>
                        <p class="disc-muted">{{ $row['summary'] }}</p>
                        <p class="disc-saved__new">
                            @if ($row['new'] > 0)<x-ds.badge tone="info" icon="sparkle">{{ number_format($row['new']) }} new {{ \Illuminate\Support\Str::plural('match', $row['new']) }}</x-ds.badge>@else<span class="disc-muted">No new matches since you saved it</span>@endif
                        </p>
                    </div>
                    <div class="disc-saved__actions">
                        <form method="POST" action="{{ route('account.saved-searches.update', $s) }}" class="disc-saved__freq">
                            @csrf @method('PATCH')
                            <label for="freq-{{ $s->id }}">E-mail new matches</label>
                            <div class="ds-select-wrap">
                                <select id="freq-{{ $s->id }}" name="notify_frequency" class="ds-select" data-autosubmit>
                                    @foreach (['off' => 'Never', 'daily' => 'Daily', 'weekly' => 'Weekly'] as $value => $label)<option value="{{ $value }}" @selected($s->notify_frequency === $value)>{{ $label }}</option>@endforeach
                                </select>
                                <x-ds.icon name="chevron-down" :size="18" />
                            </div>
                            <noscript><button type="submit" class="ds-btn ds-btn--quiet ds-btn--sm">Save</button></noscript>
                        </form>
                        <div class="disc-saved__buttons">
                            <x-ds.button :href="$row['url']" size="sm" icon="search">Run search</x-ds.button>
                            <x-ds.button variant="quiet" size="sm" icon="edit" :data-dialog-open="'rename-'.$s->id">Rename</x-ds.button>
                            <x-ds.button variant="danger" size="sm" icon="trash" :data-dialog-open="'delete-'.$s->id">Delete</x-ds.button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="disc-muted">You can keep {{ $limit }} saved searches. Digests are sent at most once a day or a week and only when something is new.</p>

        @foreach ($rows as $row)
            @php($s = $row['model'])
            <x-ds.dialog :id="'rename-'.$s->id" title="Rename this search">
                <form method="POST" action="{{ route('account.saved-searches.update', $s) }}" id="rename-form-{{ $s->id }}">
                    @csrf @method('PATCH')
                    <x-ds.input label="Name" name="name" :id="'rename-name-'.$s->id" :value="$s->name" required maxlength="80" />
                </form>
                <x-slot:footer>
                    <x-ds.button variant="quiet" data-dialog-close>Cancel</x-ds.button>
                    <x-ds.button type="submit" :form="'rename-form-'.$s->id">Save name</x-ds.button>
                </x-slot:footer>
            </x-ds.dialog>
            <x-ds.confirm-dialog :id="'delete-'.$s->id" title="Delete this saved search?" :action="route('account.saved-searches.destroy', $s)" method="DELETE" confirm-label="Delete search">
                "{{ $s->name }}" and its e-mail digest will be removed. This cannot be undone. The search itself still works from the search box.
            </x-ds.confirm-dialog>
        @endforeach
    @else
        <x-ds.empty-state title="No saved searches yet" icon="bookmark">Search for something, then choose Save this search. You will see here what is new on it.
            <x-slot:actions><x-ds.button :href="route('products.index')">Start a search</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @endif
</x-account-layout>
