<?php

namespace App\Services\Search;

use Illuminate\Database\Query\Builder;

/**
 * PostgreSQL full-text search over the generated, weighted tsvector (title A, brand and category B, store and
 * attributes C, description D): `to_tsquery` with prefix matching, ranked by ts_rank_cd. Needs no extension. Typo
 * tolerance comes from the shared vocabulary, using pg_trgm when it is installed (Vocabulary::correct) and an edit
 * distance otherwise, so the engine behaves the same either way, only faster with the extension.
 */
class PostgresSearch extends PortableSearch
{
    public function name(): string
    {
        return 'postgres';
    }

    protected function whereText(Builder $query, array $slots, string $mode): void
    {
        $tsquery = $this->tsQuery($slots, $mode);

        if ($tsquery === '') {
            return;
        }

        $query->whereRaw("d.search_vector @@ to_tsquery('simple', ?)", [$tsquery]);
    }

    protected function textScore(array $slots, string $mode): array
    {
        $tsquery = $this->tsQuery($slots, $mode);

        if ($tsquery === '') {
            return ['0', []];
        }

        return ["(ts_rank_cd(d.search_vector, to_tsquery('simple', ?), 32) * 10)", [$tsquery]];
    }

    /**
     * (laptop:* | notebook:*) & (core:*) -- words are reduced to letters and digits, so the string cannot carry
     * tsquery syntax, and every word is quoted. Words of three letters or more match as prefixes; shorter words
     * (tv, 5g) must match whole.
     *
     * @param  list<list<string>>  $slots
     */
    protected function tsQuery(array $slots, string $mode): string
    {
        $groups = [];

        foreach ($slots as $alternatives) {
            $options = [];

            foreach ($alternatives as $alternative) {
                $words = [];

                foreach (explode(' ', $alternative) as $word) {
                    $word = preg_replace('/[^a-z0-9.]/', '', $word) ?? '';
                    $word = trim($word, '.');

                    if ($word !== '') {
                        $words[] = "'".$word."'".(strlen($word) >= 3 ? ':*' : '');
                    }
                }

                if ($words !== []) {
                    $options[] = '('.implode(' & ', $words).')';
                }
            }

            if ($options !== []) {
                $groups[] = '('.implode(' | ', $options).')';
            }
        }

        return implode($mode === 'any' ? ' | ' : ' & ', $groups);
    }

    public function suggestProducts(string $typed, int $limit = 6): array
    {
        $words = Text::tokens($typed);

        if ($words === []) {
            return [];
        }

        $slots = array_map(fn ($w) => [$w], $words);
        $tsquery = $this->tsQuery($slots, 'all');

        if ($tsquery === '') {
            return [];
        }

        $query = $this->base()->whereRaw("d.search_vector @@ to_tsquery('simple', ?)", [$tsquery]);

        return $this->suggestRows(
            $query->orderByRaw('CASE WHEN p.stock > 0 THEN 0 ELSE 1 END, ts_rank_cd(d.search_vector, to_tsquery(\'simple\', ?), 32) DESC, d.sold_count DESC, p.id DESC', [$tsquery]),
            $limit,
        );
    }
}
