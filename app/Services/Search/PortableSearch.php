<?php

namespace App\Services\Search;

use Illuminate\Database\Query\Builder;

/**
 * Search that needs nothing from the database but LIKE: used on SQLite (tests, local) and wherever PostgreSQL
 * full-text is unavailable. The documents are already lowercase, accent-free and plural-folded, so a plain LIKE on
 * a word start is case-insensitive in effect. A word matches where it starts a word of the searchable text (the same
 * meaning as the full-text engine's prefix match), and the order is a score built from where the words were found.
 */
class PortableSearch extends AbstractSearch
{
    private const WEIGHTS = ['title_n' => 3.0, 'brand_n' => 2.0, 'category_n' => 1.5, 'attributes_n' => 1.0, 'store_n' => 0.3, 'description_n' => 0.5];

    public function name(): string
    {
        return 'portable';
    }

    protected function whereText(Builder $query, array $slots, string $mode): void
    {
        $bindings = [];
        $clauses = [];

        foreach ($slots as $alternatives) {
            $clauses[] = $this->slotCondition('d.body_n', $alternatives, $bindings);
        }

        $glue = $mode === 'any' ? ' OR ' : ' AND ';
        $query->whereRaw('('.implode($glue, $clauses).')', $bindings);
    }

    protected function textScore(array $slots, string $mode): array
    {
        $parts = [];
        $bindings = [];

        foreach ($slots as $alternatives) {
            foreach (self::WEIGHTS as $column => $weight) {
                $parts[] = 'CASE WHEN '.$this->slotCondition('d.'.$column, $alternatives, $bindings).' THEN '.$weight.' ELSE 0 END';
            }
        }

        return ['('.implode(' + ', $parts).')', $bindings];
    }

    /**
     * One meaning with its alternatives, as a condition on a column: any alternative whose every word starts a word.
     *
     * @param  list<string>  $alternatives
     * @param  list<mixed>  $bindings
     */
    private function slotCondition(string $column, array $alternatives, array &$bindings): string
    {
        $options = [];

        foreach ($alternatives as $alternative) {
            $words = array_values(array_filter(explode(' ', $alternative), fn ($w) => $w !== ''));

            if ($words === []) {
                continue;
            }

            $all = [];

            foreach ($words as $word) {
                $all[] = "(' ' || {$column}) LIKE ? ESCAPE '\\'";
                $bindings[] = '% '.$this->likeEscape($word).'%';
            }

            $options[] = '('.implode(' AND ', $all).')';
        }

        return $options === [] ? '1 = 0' : '('.implode(' OR ', $options).')';
    }

    public function suggestProducts(string $typed, int $limit = 6): array
    {
        $words = Text::tokens($typed);

        if ($words === []) {
            return [];
        }

        $query = $this->base();

        foreach ($words as $word) {
            $query->whereRaw("(' ' || d.title_n) LIKE ? ESCAPE '\\'", ['% '.$this->likeEscape($word).'%']);
        }

        return $this->suggestRows($query->orderByRaw('CASE WHEN d.title_n LIKE ? ESCAPE \'\\\' THEN 0 ELSE 1 END, CASE WHEN p.stock > 0 THEN 0 ELSE 1 END, d.sold_count DESC, p.id DESC', [$this->likeEscape($words[0]).'%']), $limit);
    }

    /**
     * @return list<array{id: int, title: string, price: string, image_id: int|null, store: string, in_stock: bool}>
     */
    protected function suggestRows(Builder $query, int $limit): array
    {
        $rows = $query->select('p.id', 'p.name', 'p.price', 's.name as store', 'p.stock')
            ->selectRaw('(select pi.id from product_images pi where pi.product_id = p.id order by pi.sort_order, pi.id limit 1) as image_id')
            ->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'title' => $r->name,
            'price' => number_format((float) $r->price, 2, '.', ''),
            'image_id' => $r->image_id !== null ? (int) $r->image_id : null,
            'store' => $r->store,
            'in_stock' => (int) $r->stock > 0,
        ])->all();
    }
}
