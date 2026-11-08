<?php

namespace App\Http\Controllers\Discovery;

use App\Contracts\CategoryTree;
use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use App\Services\Discovery\SavedSearches;
use App\Services\Search\SearchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** A signed-in person's saved searches: list, run, rename, change how often new matches are e-mailed, delete. Owner only (SavedSearchPolicy). */
class SavedSearchController extends Controller
{
    public function index(Request $request, SavedSearches $saved): View
    {
        $searches = SavedSearch::query()->where('user_id', $request->user()->id)->latest('id')->get();

        $rows = $searches->map(function (SavedSearch $s) use ($saved) {
            return [
                'model' => $s,
                'summary' => $this->summary($s),
                'url' => route('search', $s->toRequest()->toArray()),
                'new' => count($saved->newMatches($s)),
            ];
        });

        return view('discover.saved-searches', [
            'rows' => $rows,
            'limit' => (int) config('discovery.wishlist.max_saved_searches', 20),
        ]);
    }

    public function store(Request $request, SavedSearches $saved): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'notify_frequency' => ['nullable', 'in:'.implode(',', SavedSearch::FREQUENCIES)],
            'search' => ['required', 'array'],
        ]);

        try {
            $saved->save($request->user(), $data['name'], SearchRequest::fromArray($this->plain($data['search'])), $data['notify_frequency'] ?? 'off');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('account.saved-searches')->with('status', 'Search saved.');
    }

    public function update(Request $request, SavedSearch $savedSearch, SavedSearches $saved): RedirectResponse
    {
        Gate::authorize('update', $savedSearch);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'notify_frequency' => ['sometimes', 'in:'.implode(',', SavedSearch::FREQUENCIES)],
        ]);

        if (isset($data['name'])) {
            $savedSearch->update(['name' => trim($data['name'])]);
        }

        if (isset($data['notify_frequency'])) {
            $saved->setFrequency($savedSearch, $data['notify_frequency']);
        }

        return redirect()->route('account.saved-searches')->with('status', 'Saved search updated.');
    }

    public function destroy(SavedSearch $savedSearch): RedirectResponse
    {
        Gate::authorize('delete', $savedSearch);
        $savedSearch->delete();

        return redirect()->route('account.saved-searches')->with('status', 'Saved search deleted.');
    }

    /** Scalars and lists of scalars only: whatever the form carried, nothing nested deeper is trusted. @param  array<string, mixed>  $input @return array<string, mixed> */
    private function plain(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if (is_scalar($value)) {
                $out[$key] = $value;
            } elseif (is_array($value)) {
                $out[$key] = array_map(fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_scalar')) : (is_scalar($v) ? $v : null), $value);
            }
        }

        return $out;
    }

    private function summary(SavedSearch $s): string
    {
        $request = $s->toRequest();
        $parts = [];

        if ($request->text !== '') {
            $parts[] = '"'.$request->text.'"';
        }

        if ($request->categoryId !== null) {
            $parts[] = 'in '.(app(CategoryTree::class)->find($request->categoryId)['name'] ?? 'a category');
        }

        if ($request->brands) {
            $parts[] = implode(' or ', $request->brands);
        }

        if ($request->priceMin !== null || $request->priceMax !== null) {
            $parts[] = match (true) {
                $request->priceMin !== null && $request->priceMax !== null => 'R'.number_format($request->priceMin, 0).' to R'.number_format($request->priceMax, 0),
                $request->priceMax !== null => 'up to R'.number_format($request->priceMax, 0),
                default => 'from R'.number_format((float) $request->priceMin, 0),
            };
        }

        if ($request->inStock) {
            $parts[] = 'in stock';
        }

        if ($request->minRating) {
            $parts[] = $request->minRating.' stars and up';
        }

        foreach ($request->attributes as $key => $values) {
            $parts[] = str_replace('_', ' ', $key).' '.implode(' or ', $values);
        }

        if ($request->regions) {
            $parts[] = 'from '.implode(' or ', $request->regions);
        }

        return $parts ? implode(', ', $parts) : 'All products';
    }
}
