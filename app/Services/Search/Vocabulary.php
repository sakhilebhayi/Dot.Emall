<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The words the catalogue actually contains (search_terms): every word of every title, plus brand names and
 * category names as phrases. It is what typo correction compares against and what query understanding uses to
 * recognise a brand or a category inside a typed request. It is built from real listings only.
 */
class Vocabulary
{
    private const BRANDS_KEY = 'discovery.vocab.brands.v1';

    private const CATEGORIES_KEY = 'discovery.vocab.categories.v1';

    /** @var array<string, array{display: string}>|null */
    private ?array $brands = null;

    /** @var array<string, array{id: int, display: string}>|null */
    private ?array $categories = null;

    public function __construct(private readonly SearchCapabilities $capabilities) {}

    public static function forget(): void
    {
        foreach ([self::BRANDS_KEY, self::CATEGORIES_KEY] as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
            }
        }

        if (app()->resolved(self::class)) {
            $self = app(self::class);
            $self->brands = $self->categories = null;
        }
    }

    /**
     * Brand phrases in searched form => display name.
     *
     * @return array<string, array{display: string}>
     */
    public function brands(): array
    {
        return $this->brands ??= $this->cached(self::BRANDS_KEY, function () {
            return DB::table('search_terms')->where('kind', 'brand')->get(['term', 'display'])
                ->mapWithKeys(fn ($r) => [$r->term => ['display' => $r->display ?? $r->term]])->all();
        });
    }

    /**
     * Category phrases in searched form => id and name.
     *
     * @return array<string, array{id: int, display: string}>
     */
    public function categories(): array
    {
        return $this->categories ??= $this->cached(self::CATEGORIES_KEY, function () {
            return DB::table('search_terms')->where('kind', 'category')->get(['term', 'display', 'ref_id'])
                ->mapWithKeys(fn ($r) => [$r->term => ['id' => (int) $r->ref_id, 'display' => $r->display ?? $r->term]])->all();
        });
    }

    /** Whether this folded word appears in at least one listing. */
    public function knows(string $token): bool
    {
        return DB::table('search_terms')->where('kind', 'word')->where('term', $token)->exists();
    }

    /**
     * The closest known word to a misspelt one, or null when nothing is close enough to suggest honestly.
     * A word is only corrected when the candidate is within an edit distance that scales with its length (1 edit up
     * to 5 letters, 2 beyond), and never to a word in $avoid. Letters of the original word that the candidate keeps
     * make the answer predictable: "samsnug" -> "samsung", "lapto" is a prefix and is left alone.
     */
    public function correct(string $token, array $avoid = []): ?string
    {
        $length = strlen($token);

        if ($length < 4 || ctype_digit($token) || ! Schema::hasTable('search_terms')) {
            return null;
        }

        $maxDistance = $length <= 5 ? 1 : 2;

        $candidates = $this->capabilities->trigram()
            ? $this->trigramCandidates($token)
            : $this->windowCandidates($token, $maxDistance);

        $best = null;
        $bestDistance = PHP_INT_MAX;
        $bestFrequency = -1;

        foreach ($candidates as $candidate) {
            if ($candidate->term === $token || in_array($candidate->term, $avoid, true)) {
                continue;
            }

            $distance = $this->distance($token, $candidate->term);

            if ($distance > $maxDistance) {
                continue;
            }

            if ($distance < $bestDistance || ($distance === $bestDistance && $candidate->doc_freq > $bestFrequency)) {
                $best = $candidate->term;
                $bestDistance = $distance;
                $bestFrequency = (int) $candidate->doc_freq;
            }
        }

        return $best;
    }

    /**
     * Records words seen on newly indexed listings so they are known at once; counts are corrected by the next
     * full rebuild (SearchIndexer::rebuildVocabulary).
     *
     * @param  array<int, string>  $words
     */
    public function learn(array $words): void
    {
        $rows = [];

        foreach (array_unique($words) as $word) {
            if ($word !== '' && strlen($word) <= 80) {
                $rows[] = ['term' => $word, 'kind' => 'word', 'doc_freq' => 1, 'ref_id' => null, 'display' => null];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('search_terms')->insertOrIgnore($chunk);
        }
    }

    /** Damerau-style distance: a swap of two neighbouring letters is one edit. */
    public function distance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);

        if (abs($la - $lb) > 3) {
            return 99;
        }

        $d = [];

        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }

        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb];
    }

    /** @return iterable<object{term: string, doc_freq: int}> */
    private function trigramCandidates(string $token): iterable
    {
        try {
            return DB::select(
                "select term, doc_freq from search_terms where kind = 'word' and term % ? order by similarity(term, ?) desc, doc_freq desc limit 8",
                [$token, $token],
            );
        } catch (Throwable) {
            return $this->windowCandidates($token, $token === '' ? 1 : (strlen($token) <= 5 ? 1 : 2));
        }
    }

    /**
     * Without trigram support: words of a similar length that share the first letter or the second and third
     * letters (so a wrong first letter still has a chance), compared by edit distance in PHP.
     *
     * @return iterable<object{term: string, doc_freq: int}>
     */
    private function windowCandidates(string $token, int $maxDistance): iterable
    {
        $length = strlen($token);

        return DB::table('search_terms')
            ->where('kind', 'word')
            ->whereBetween(DB::raw('length(term)'), [$length - $maxDistance, $length + $maxDistance])
            ->where(function ($q) use ($token) {
                $q->where('term', 'like', substr($token, 0, 1).'%')
                    ->orWhere('term', 'like', '_'.substr($token, 1, 2).'%');
            })
            ->orderByDesc('doc_freq')
            ->limit(2000)
            ->get(['term', 'doc_freq']);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $load
     * @return T
     */
    private function cached(string $key, callable $load): mixed
    {
        try {
            return Cache::remember($key, 600, $load);
        } catch (Throwable) {
            return $load();
        }
    }
}
