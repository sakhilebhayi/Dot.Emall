<?php

namespace App\Console\Commands;

use App\Models\CategoryAttribute;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use App\Services\Catalogue\Taxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the starting category tree and its spec definitions. Idempotent (matched by slug; names and ordering are
 * refreshed, nothing is ever deleted, categories a seller or operator added are left alone) and never run
 * automatically: an operator runs it once, on purpose.
 */
class SeedCategories extends Command
{
    protected $signature = 'emall:seed-categories {--without-attributes : Only the tree, not the spec definitions}';

    protected $description = 'Create or refresh the starting category tree and its spec definitions (idempotent)';

    public function handle(CategoryTree $tree): int
    {
        $created = 0;
        $attributes = 0;

        DB::transaction(function () use (&$created, &$attributes) {
            $order = 0;

            foreach (Taxonomy::categories() as $slug => $definition) {
                $root = $this->upsert($slug, $definition['name'], null, $order++, $created);

                $childOrder = 0;
                foreach ($definition['children'] as $childSlug => $childName) {
                    $this->upsert($childSlug, $childName, $root, $childOrder++, $created);
                }
            }

            if (! $this->option('without-attributes')) {
                foreach (Taxonomy::attributes() as $slug => $rows) {
                    $category = StoreCategory::query()->where('slug', $slug)->first();

                    if ($category === null) {
                        continue;
                    }

                    foreach ($rows as $position => [$key, $label, $type, $unit, $options, $facetable, $required]) {
                        CategoryAttribute::updateOrCreate(
                            ['category_id' => $category->id, 'key' => $key],
                            ['label' => $label, 'type' => $type, 'unit' => $unit, 'options' => $options, 'is_facetable' => $facetable, 'is_required' => $required, 'position' => $position],
                        );
                        $attributes++;
                    }
                }
            }
        });

        CategoryTree::forgetCounts();

        $this->info("Categories: {$created} created, ".StoreCategory::query()->count().' in total. Spec definitions applied: '.$attributes.'.');

        return self::SUCCESS;
    }

    private function upsert(string $slug, string $name, ?StoreCategory $parent, int $order, int &$created): StoreCategory
    {
        $category = StoreCategory::query()->where('slug', $slug)->first();

        if ($category === null) {
            $category = StoreCategory::create(['name' => $name, 'slug' => $slug, 'parent_id' => $parent?->id, 'sort_order' => $order]);
            $created++;

            return $category->refresh();
        }

        $category->forceFill(['name' => $name, 'sort_order' => $order])->save();

        if ((int) $category->parent_id !== (int) $parent?->id) {
            app(CategoryTree::class)->move($category, $parent);
        }

        return $category->refresh();
    }
}
