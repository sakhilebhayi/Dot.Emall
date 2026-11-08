<?php

namespace App\Services\Discovery;

use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saved searches: a query and its filters kept under a name, optionally e-mailed as a digest of NEW matches only.
 * "New" means a listing that was not in the result when the search was saved or last reported.
 */
class SavedSearches
{
    public function __construct(private readonly SearchEngine $engine) {}

    /**
     * @throws ValidationException
     */
    public function save(User $user, string $name, SearchRequest $request, string $frequency = 'off'): SavedSearch
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Give the search a name of up to 80 characters.']);
        }

        if (! in_array($frequency, SavedSearch::FREQUENCIES, true)) {
            throw ValidationException::withMessages(['notify_frequency' => 'Choose off, daily or weekly.']);
        }

        if ($request->text === '' && $request->filters() === []) {
            throw ValidationException::withMessages(['query' => 'There is nothing to save: search for something or choose a filter first.']);
        }

        if (SavedSearch::query()->where('user_id', $user->id)->count() >= (int) config('discovery.wishlist.max_saved_searches', 20)) {
            throw ValidationException::withMessages(['name' => 'You have saved the most searches allowed. Delete one first.']);
        }

        $saved = new SavedSearch([
            'user_id' => $user->id,
            'name' => Str::limit($name, 80, ''),
            'query' => $request->text !== '' ? $request->text : null,
            'filters' => $request->filters(),
            'notify_frequency' => $frequency,
        ]);
        $saved->seen_product_ids = $this->currentIds($saved);
        $saved->save();

        return $saved;
    }

    public function setFrequency(SavedSearch $saved, string $frequency): SavedSearch
    {
        if (! in_array($frequency, SavedSearch::FREQUENCIES, true)) {
            throw ValidationException::withMessages(['notify_frequency' => 'Choose off, daily or weekly.']);
        }

        $saved->update(['notify_frequency' => $frequency]);

        return $saved;
    }

    /**
     * Product ids that match now and were not in the result last time.
     *
     * @return list<int>
     */
    public function newMatches(SavedSearch $saved): array
    {
        $now = $this->currentIds($saved);

        return array_values(array_diff($now, $saved->seen_product_ids ?? []));
    }

    /** Remembers what the person has now been told about. @param  list<int>  $ids */
    public function markSeen(SavedSearch $saved, array $ids): void
    {
        $seen = array_slice(array_values(array_unique(array_merge($saved->seen_product_ids ?? [], $ids))), -500);
        $saved->forceFill(['seen_product_ids' => $seen, 'last_notified_at' => now()])->save();
    }

    /** @return list<int> */
    private function currentIds(SavedSearch $saved): array
    {
        return $this->engine->search($saved->toRequest()->with(['perPage' => 60, 'page' => 1, 'sort' => 'newest']))->productIds();
    }
}
