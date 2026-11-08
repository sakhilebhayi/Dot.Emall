<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Expands a word or phrase into the words that mean the same thing (laptop / notebook, takkies / sneakers).
 * The whole table is small, so it is read once and remembered; saving a SearchSynonym forgets it.
 */
class Synonyms
{
    private const CACHE_KEY = 'discovery.synonyms.v1';

    /** @var array<string, list<string>>|null */
    private ?array $map = null;

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
        }

        if (app()->resolved(self::class)) {
            app(self::class)->map = null;
        }
    }

    /**
     * Every word equivalent to $phrase (a normalised, folded word or phrase), NOT including $phrase itself.
     *
     * @return list<string>
     */
    public function equivalents(string $phrase): array
    {
        return $this->map()[$phrase] ?? [];
    }

    public function has(string $phrase): bool
    {
        return isset($this->map()[$phrase]);
    }

    /** The longest phrase length (in words) with any synonym, so callers know how far to look ahead. */
    public function longest(): int
    {
        $longest = 1;

        foreach (array_keys($this->map()) as $phrase) {
            $longest = max($longest, substr_count($phrase, ' ') + 1);
        }

        return $longest;
    }

    /** @return array<string, list<string>> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        try {
            $this->map = Cache::remember(self::CACHE_KEY, 600, fn () => $this->load());
        } catch (Throwable) {
            $this->map = $this->load();
        }

        return $this->map;
    }

    /** @return array<string, list<string>> */
    private function load(): array
    {
        if (! Schema::hasTable('search_synonyms')) {
            return [];
        }

        $groups = [];

        foreach (DB::table('search_synonyms')->orderBy('id')->get(['group_key', 'term']) as $row) {
            $groups[$row->group_key][] = $row->term;
        }

        $map = [];

        foreach ($groups as $terms) {
            foreach ($terms as $term) {
                $others = array_values(array_diff($terms, [$term]));
                $map[$term] = array_values(array_unique(array_merge($map[$term] ?? [], $others)));
            }
        }

        return $map;
    }
}
