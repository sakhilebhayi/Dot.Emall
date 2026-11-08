<?php

namespace App\Services\Discovery;

use App\Services\Search\Text;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The record of what was searched for. It exists to power "recent searches", "popular searches" and the operator's
 * list of searches that found nothing, and it is kept deliberately thin: the normalised query, how many results it
 * had, a keyed hash of who (never a user id) and when. Queries that look like personal data (an e-mail address, a
 * long run of digits) are not stored at all. Rows are deleted after 90 days (emall:discovery-prune).
 */
class SearchLog
{
    public function record(Actor $actor, string $query, int $results): void
    {
        $hash = $actor->hash();
        $clean = $this->clean($query);

        if ($hash === null || $clean === null) {
            return;
        }

        try {
            // A refined or repeated search within two minutes is the same intent: count it once.
            $repeat = DB::table('search_queries')->where('actor_hash', $hash)->where('query', $clean)
                ->where('created_at', '>=', now()->subMinutes(2))->exists();

            if ($repeat) {
                return;
            }

            DB::table('search_queries')->insert(['query' => $clean, 'result_count' => $results, 'actor_hash' => $hash, 'created_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Could not record a search.', ['error' => class_basename($e)]);
        }
    }

    /** The normalised form that is stored, or null when the text must not be stored. */
    public function clean(string $query): ?string
    {
        // Personal data typed into a search box: an address, or a long number (phone, card, ID). Checked on the raw
        // text, before normalising strips the @.
        if (str_contains($query, '@') || preg_match('/\d{7,}/', preg_replace('/[\s\-().+]/', '', $query) ?? '') === 1) {
            return null;
        }

        $query = trim(preg_replace('/\s+/', ' ', Text::normalise($query)) ?? '');

        if ($query === '' || strlen($query) < 2 || strlen($query) > 120) {
            return null;
        }

        return $query;
    }

    /**
     * The person's own latest distinct searches.
     *
     * @return Collection<int, string>
     */
    public function recentFor(Actor $actor, int $limit = 5, string $startingWith = ''): Collection
    {
        $hash = $actor->hash();

        if ($hash === null) {
            return collect();
        }

        $query = DB::table('search_queries')->where('actor_hash', $hash)->where('result_count', '>', 0);

        if ($startingWith !== '') {
            $query->where('query', 'like', $this->like($startingWith).'%');
        }

        return $query->groupBy('query')->selectRaw('query, max(created_at) as at')->orderByDesc('at')->limit($limit)->pluck('query');
    }

    /**
     * Searches at least N different people ran in the window that found something. Never a single person's habit.
     * The top 100 are computed at most every few minutes in production (the aggregate scans a month of searches), so
     * a keystroke in the search box does not; a typed prefix filters that list.
     *
     * @return Collection<int, array{query: string, people: int}>
     */
    public function popular(int $limit = 5, string $startingWith = ''): Collection
    {
        $ttl = (int) config('discovery.suggest.popular_cache_seconds', 0);
        $all = $ttl > 0 ? Cache::remember('discovery.popular.v1', $ttl, fn () => $this->popularAggregate()->all()) : $this->popularAggregate()->all();
        $prefix = Text::normalise($startingWith);

        return collect($all)
            ->when($prefix !== '', fn (Collection $c) => $c->filter(fn (array $row) => str_starts_with($row['query'], $prefix)))
            ->take($limit)->values();
    }

    /** @return Collection<int, array{query: string, people: int}> */
    private function popularAggregate(): Collection
    {
        $min = (int) config('discovery.suggest.popular_min_people', 3);
        $since = now()->subDays((int) config('discovery.suggest.popular_window_days', 30));

        return DB::table('search_queries')->where('created_at', '>=', $since)->where('result_count', '>', 0)
            ->groupBy('query')
            ->selectRaw('query, count(distinct actor_hash) as people')
            ->havingRaw('count(distinct actor_hash) >= ?', [$min])
            ->orderByDesc('people')->orderBy('query')->limit(100)->get()
            ->map(fn ($r) => ['query' => $r->query, 'people' => (int) $r->people]);
    }

    /**
     * For the operator: searches that found nothing, most repeated first.
     *
     * @return Collection<int, array{query: string, searches: int, people: int, last_at: string}>
     */
    public function zeroResults(int $days = 30, int $limit = 50): Collection
    {
        return DB::table('search_queries')
            ->where('result_count', 0)->where('created_at', '>=', now()->subDays($days))
            ->groupBy('query')
            ->selectRaw('query, count(*) as searches, count(distinct actor_hash) as people, max(created_at) as last_at')
            ->orderByDesc('searches')->orderBy('query')->limit($limit)->get()
            ->map(fn ($r) => ['query' => $r->query, 'searches' => (int) $r->searches, 'people' => (int) $r->people, 'last_at' => (string) $r->last_at]);
    }

    public function prune(?int $days = null): int
    {
        return DB::table('search_queries')->where('created_at', '<', now()->subDays($days ?? (int) config('discovery.retention.search_queries_days', 90)))->delete();
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Text::normalise($value));
    }
}
