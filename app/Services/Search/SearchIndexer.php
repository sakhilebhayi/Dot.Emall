<?php

namespace App\Services\Search;

use App\Contracts\CatalogueSource;
use App\Contracts\CategoryTree;
use App\Services\Discovery\CountedOrders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maintains search_documents, search_document_attributes and the vocabulary. Documents exist for every listing that
 * is not archived (whether or not it is currently browsable): visibility is checked live at query time, so approving
 * a listing or switching it on needs no re-index. Only a change to the TEXT of a listing (its name, description,
 * category, brand, attributes, store name or region) needs one, and the observers, the scheduled stale sweep and
 * `php artisan emall:search-reindex` provide that.
 */
class SearchIndexer
{
    public function __construct(
        private readonly CatalogueSource $catalogue,
        private readonly CategoryTree $categories,
        private readonly Vocabulary $vocabulary,
        private readonly CountedOrders $counted,
    ) {}

    /**
     * (Re)index these products. Products that are archived or gone lose their document.
     *
     * @param  array<int, int>  $productIds
     */
    public function index(array $productIds): int
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $done = 0;

        foreach (array_chunk($productIds, 200) as $chunk) {
            $done += $this->indexChunk($chunk);
        }

        return $done;
    }

    public function remove(int $productId): void
    {
        DB::table('search_document_attributes')->where('product_id', $productId)->delete();
        DB::table('search_documents')->where('product_id', $productId)->delete();
    }

    /**
     * Every listing, from scratch. Returns [indexed, removed].
     *
     * @return array{0: int, 1: int}
     */
    public function rebuild(?callable $progress = null): array
    {
        $indexed = 0;

        DB::table('products')->whereNull('deleted_at')->orderBy('id')->select('id')->chunk(500, function ($rows) use (&$indexed, $progress) {
            $indexed += $this->indexChunk($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

            if ($progress) {
                $progress($indexed);
            }
        });

        // Documents whose product is archived or hard deleted.
        $removed = DB::table('search_documents')->whereNotIn('product_id', DB::table('products')->whereNull('deleted_at')->select('id'))->delete();
        DB::table('search_document_attributes')->whereNotIn('product_id', DB::table('search_documents')->select('product_id'))->delete();

        $this->rebuildVocabulary();
        $this->refreshSalesCounts();

        return [$indexed, $removed];
    }

    /**
     * Products with no document, or edited since their document was written. Cheap enough to run every few minutes.
     */
    public function reindexStale(int $limit = 2000): int
    {
        $ids = DB::table('products as p')
            ->leftJoin('search_documents as d', 'd.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                $q->whereNull('d.product_id')->orWhereColumn('p.updated_at', '>', 'd.indexed_at');
            })
            ->orderBy('p.id')->limit($limit)->pluck('p.id')->map(fn ($id) => (int) $id)->all();

        $stale = $this->index($ids);

        // Documents of archived products.
        $gone = DB::table('search_documents')->whereNotIn('product_id', DB::table('products')->whereNull('deleted_at')->select('id'))->pluck('product_id');

        foreach ($gone as $id) {
            $this->remove((int) $id);
        }

        return $stale + $gone->count();
    }

    /** Units sold in counted orders, written onto the documents so "best selling" is a plain sort. */
    public function refreshSalesCounts(): int
    {
        $sold = $this->counted->unitsSoldByProduct();

        DB::table('search_documents')->where('sold_count', '>', 0)->update(['sold_count' => 0]);

        foreach (array_chunk($sold, 500, true) as $chunk) {
            foreach ($chunk as $productId => $units) {
                DB::table('search_documents')->where('product_id', $productId)->update(['sold_count' => $units]);
            }
        }

        return count($sold);
    }

    /** Word counts, brands and categories, recomputed from the documents. */
    public function rebuildVocabulary(): int
    {
        $words = [];

        DB::table('search_documents')->select('product_id', 'title_n', 'brand_n', 'brand', 'category_n', 'attributes_n', 'category_id', 'category_name')
            ->orderBy('product_id')->chunk(1000, function ($docs) use (&$words) {
                foreach ($docs as $doc) {
                    foreach (explode(' ', trim($doc->title_n.' '.$doc->brand_n.' '.$doc->category_n.' '.$doc->attributes_n)) as $word) {
                        if ($word !== '' && strlen($word) <= 80) {
                            $words[$word] = ($words[$word] ?? 0) + 1;
                        }
                    }
                }
            });

        $rows = [];

        foreach ($words as $word => $frequency) {
            $rows[] = ['term' => (string) $word, 'kind' => 'word', 'doc_freq' => $frequency, 'ref_id' => null, 'display' => null];
        }

        $brands = DB::table('search_documents')->whereNotNull('brand_n')->groupBy('brand_n')->selectRaw('brand_n, max(brand) as brand, count(*) as c')->get();

        foreach ($brands as $brand) {
            $rows[] = ['term' => $brand->brand_n, 'kind' => 'brand', 'doc_freq' => (int) $brand->c, 'ref_id' => null, 'display' => $brand->brand];
        }

        // Every category that has a listing, plus its ancestors, so "laptops" is recognised when only a child has stock.
        $categoryIds = DB::table('search_documents')->whereNotNull('category_id')->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)->all();
        $withAncestors = [];

        foreach ($categoryIds as $id) {
            for ($row = $this->categories->find($id), $guard = 0; $row !== null && $guard++ < 12; $row = $row['parent_id'] ? $this->categories->find($row['parent_id']) : null) {
                $withAncestors[$row['id']] = $row;
            }
        }

        foreach ($this->categoryTerms(array_values($withAncestors)) as $term => $category) {
            $rows[] = ['term' => $term, 'kind' => 'category', 'doc_freq' => 1, 'ref_id' => $category['id'], 'display' => $category['name']];
        }

        DB::transaction(function () use ($rows) {
            DB::table('search_terms')->delete();

            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('search_terms')->insertOrIgnore($chunk);
            }
        });

        Vocabulary::forget();

        return count($rows);
    }

    /** @param  array<int, int>  $ids */
    private function indexChunk(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $products = DB::table('products as p')
            ->join('stores as s', 's.id', '=', 'p.store_id')
            ->whereIn('p.id', $ids)->whereNull('p.deleted_at')
            ->get(['p.id', 'p.name', 'p.description', 'p.category_id', 'p.store_id', 'p.sku', 's.name as store_name']);

        $present = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($ids, $present) as $missing) {
            $this->remove($missing);
        }

        if ($products->isEmpty()) {
            return 0;
        }

        $brands = $this->catalogue->brands($present);
        $attributes = $this->mergeVariantOptions($this->catalogue->attributes($present), $this->catalogue->variantOptions($present));
        $regions = $this->catalogue->storeRegions($products->pluck('store_id')->unique()->map(fn ($id) => (int) $id)->all());
        $categories = $this->categories->many($products->pluck('category_id')->filter()->unique()->map(fn ($id) => (int) $id)->all());
        $now = now();

        $documents = [];
        $attributeRows = [];
        $learned = [];

        foreach ($products as $product) {
            $id = (int) $product->id;
            $brand = $brands[$id] ?? null;
            $category = $product->category_id !== null ? ($categories[(int) $product->category_id] ?? null) : null;
            $categoryText = $category !== null ? implode(' ', $this->categories->path((int) $category['id'])) : '';
            $attributeText = [];

            foreach ($attributes[$id] ?? [] as $attribute) {
                $text = trim($attribute['value'].' '.($attribute['unit'] ?? ''));
                $attributeText[] = $text;
                $valueN = Text::index($text);

                $attributeRows[] = [
                    'product_id' => $id,
                    'attr_key' => mb_substr($attribute['key'], 0, 64),
                    'attr_label' => mb_substr($attribute['label'], 0, 128),
                    'value_text' => mb_substr($text, 0, 191),
                    'value_n' => mb_substr($valueN, 0, 191),
                    'value_num' => $attribute['number'],
                    'unit' => isset($attribute['unit']) ? mb_substr(Text::index($attribute['unit']), 0, 16) : null,
                ];
            }

            $title = Text::index($product->name);
            $brandN = $brand !== null ? Text::index($brand) : null;
            $categoryN = Text::index($categoryText);
            $attributesN = Text::index(implode(' ', $attributeText).' '.($product->sku ?? ''));
            $storeN = Text::index($product->store_name);
            $descriptionN = Text::index(mb_substr((string) $product->description, 0, 4000));

            $documents[] = [
                'product_id' => $id,
                'title' => mb_substr($product->name, 0, 255),
                'title_n' => $title,
                'brand' => $brand !== null ? mb_substr($brand, 0, 255) : null,
                'brand_n' => $brandN !== '' ? $brandN : null,
                'category_id' => $product->category_id,
                'category_name' => $category['name'] ?? null,
                'category_n' => $categoryN !== '' ? $categoryN : null,
                'store_n' => $storeN !== '' ? $storeN : null,
                'attributes_n' => $attributesN !== '' ? $attributesN : null,
                'description_n' => $descriptionN !== '' ? $descriptionN : null,
                'body_n' => trim(implode(' ', array_filter([$title, $brandN, $categoryN, $attributesN, $storeN, $descriptionN]))),
                'region' => $regions[(int) $product->store_id] ?? null,
                'indexed_at' => $now,
            ];

            foreach (explode(' ', trim($title.' '.$brandN.' '.$categoryN.' '.$attributesN)) as $word) {
                if ($word !== '') {
                    $learned[] = $word;
                }
            }
        }

        DB::transaction(function () use ($documents, $attributeRows, $present) {
            foreach (array_chunk($documents, 100) as $chunk) {
                DB::table('search_documents')->upsert(
                    $chunk,
                    ['product_id'],
                    ['title', 'title_n', 'brand', 'brand_n', 'category_id', 'category_name', 'category_n', 'store_n', 'attributes_n', 'description_n', 'body_n', 'region', 'indexed_at'],
                );
            }

            DB::table('search_document_attributes')->whereIn('product_id', $present)->delete();

            foreach (array_chunk($attributeRows, 200) as $chunk) {
                DB::table('search_document_attributes')->insert($chunk);
            }
        });

        $this->vocabulary->learn($learned);
        $this->learnBrandsAndCategories($documents);

        return count($documents);
    }

    /**
     * The phrases a shopper can type to mean one category: its name ("Laptops"), and each part of a compound name
     * ("TV, audio and video" is also found by "tv" and "audio"). A name used by several categories (three "Clothing"
     * categories sit under Fashion for women, men and kids) is never a phrase by itself, because it would silently pick
     * one of them: it is qualified by the last word of its parent ("men clothing") instead.
     *
     * Ambiguity is judged against EVERY category, but only phrases of the given ones (those with listings, and their
     * ancestors) are returned.
     *
     * @param  list<array{id: int, name: string, slug: string, parent_id: int|null}>  $wanted
     * @return array<string, array{id: int, name: string}> phrase in searched form => category
     */
    private function categoryTerms(array $wanted): array
    {
        $exact = [];
        $parts = [];

        foreach ($this->categories->all() as $category) {
            $name = $category['name'];
            $exact[Text::index($name)][$category['id']] = $category;

            foreach (preg_split('/\s*(?:,|\band\b)\s*/i', $name) ?: [] as $part) {
                $term = Text::index($part);

                if ($term !== '' && strlen($term) >= 2 && $term !== Text::index($name)) {
                    $parts[$term][$category['id']] = $category;
                }
            }
        }

        $terms = [];

        foreach ($exact as $term => $owners) {
            if ($term === '') {
                continue;
            }

            if (count($owners) === 1) {
                $terms[$term] = ['id' => reset($owners)['id'], 'name' => reset($owners)['name']];

                continue;
            }

            foreach ($owners as $owner) {
                $parent = $owner['parent_id'] !== null ? $this->categories->find($owner['parent_id']) : null;
                $last = $parent !== null ? (Text::tokens(Text::index($parent['name']))[count(Text::tokens(Text::index($parent['name']))) - 1] ?? '') : '';

                if ($last !== '') {
                    $terms[Text::index($last.' '.$owner['name'])] ??= ['id' => $owner['id'], 'name' => $owner['name']];
                }
            }
        }

        foreach ($parts as $term => $owners) {
            if (! isset($exact[$term]) && count($owners) === 1 && ! isset($terms[$term])) {
                $terms[$term] = ['id' => reset($owners)['id'], 'name' => reset($owners)['name']];
            }
        }

        $ids = array_flip(array_map(fn ($c) => $c['id'], $wanted));

        return array_filter($terms, fn ($category) => isset($ids[$category['id']]));
    }

    /**
     * The option values of a product's active variants (Size: S M L, Colour: Red) are searchable and filterable like
     * any spec: a product that comes in red is found by "red" and listed under the Colour facet. A value a seller typed
     * both as a spec and as a variant option is indexed once.
     *
     * @param  array<int, list<array{key: string, label: string, value: string, number: float|null, unit: string|null}>>  $attributes
     * @param  array<int, array<string, list<string>>>  $options
     * @return array<int, list<array{key: string, label: string, value: string, number: float|null, unit: string|null}>>
     */
    private function mergeVariantOptions(array $attributes, array $options): array
    {
        foreach ($options as $productId => $byName) {
            $seen = [];

            foreach ($attributes[$productId] ?? [] as $attribute) {
                $seen[$attribute['key'].'|'.Text::index($attribute['value'])] = true;
            }

            foreach ($byName as $name => $values) {
                $key = Str::slug($name, '_');

                foreach ($values as $value) {
                    if (isset($seen[$key.'|'.Text::index($value)])) {
                        continue;
                    }

                    $seen[$key.'|'.Text::index($value)] = true;
                    $attributes[$productId][] = ['key' => $key, 'label' => $name, 'value' => $value, 'number' => is_numeric($value) ? (float) $value : null, 'unit' => null];
                }
            }
        }

        return $attributes;
    }

    /**
     * New brands and categories are known at once; their counts are corrected by the next full rebuild.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    private function learnBrandsAndCategories(array $documents): void
    {
        $rows = [];

        foreach ($documents as $doc) {
            if ($doc['brand_n'] !== null) {
                $rows[$doc['brand_n']] = ['term' => $doc['brand_n'], 'kind' => 'brand', 'doc_freq' => 1, 'ref_id' => null, 'display' => $doc['brand']];
            }

        }

        $categoryIds = array_values(array_unique(array_filter(array_map(fn ($doc) => $doc['category_id'], $documents))));

        foreach ($this->categoryTerms(array_values($this->categories->many($categoryIds))) as $term => $category) {
            $rows['c:'.$term] = ['term' => $term, 'kind' => 'category', 'doc_freq' => 1, 'ref_id' => $category['id'], 'display' => $category['name']];
        }

        if ($rows !== []) {
            DB::table('search_terms')->insertOrIgnore(array_values($rows));
            Vocabulary::forget();
        }
    }
}
