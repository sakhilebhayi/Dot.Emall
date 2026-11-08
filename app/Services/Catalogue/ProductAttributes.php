<?php

namespace App\Services\Catalogue;

use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\StoreCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Structured specs. A category defines which attributes its products can carry (children inherit their parents'
 * definitions); a product stores the values. Numeric attributes also keep a number for range facets.
 */
class ProductAttributes
{
    /**
     * Definitions that apply to a category: its own plus every ancestor's (a child may override a key).
     *
     * @return Collection<string, CategoryAttribute> keyed by attribute key
     */
    public function definitionsFor(?StoreCategory $category): Collection
    {
        if ($category === null) {
            return collect();
        }

        $ids = array_values(array_filter(explode('/', (string) $category->path)));

        if ($ids === []) {
            $ids = [$category->id];
        }

        $order = array_flip($ids);

        return CategoryAttribute::query()
            ->whereIn('category_id', $ids)
            ->orderBy('position')->orderBy('id')
            ->get()
            ->sortBy(fn (CategoryAttribute $a) => $order[$a->category_id] ?? 0) // deeper categories last, so they win the keyBy
            ->keyBy('key');
    }

    /**
     * Replaces the product's attribute values. $values is [key => value]. Unknown keys are refused (an attribute must be
     * defined for the category), values are checked against the definition's type, enum options and requiredness.
     *
     * @param  array<string, scalar|null>  $values
     * @return Collection<int, ProductAttribute>
     *
     * @throws ValidationException
     */
    public function sync(Product $product, array $values): Collection
    {
        $definitions = $this->definitionsFor($product->category);
        $errors = [];
        $rows = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $definition = $definitions->get((string) $key);

            if ($definition === null) {
                $errors["attributes.{$key}"] = "\"{$key}\" is not a spec this category uses.";

                continue;
            }

            $error = $this->check($definition, $value);

            if ($error !== null) {
                $errors["attributes.{$key}"] = $error;

                continue;
            }

            $rows[$definition->key] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'value' => $definition->type === 'boolean' ? (filter_var($value, FILTER_VALIDATE_BOOL) ? 'Yes' : 'No') : trim((string) $value),
                'value_number' => $definition->type === 'number' ? (float) $value : null,
                'unit' => $definition->unit,
                'is_facetable' => $definition->is_facetable,
                'position' => $definition->position,
            ];
        }

        foreach ($definitions->where('is_required', true) as $definition) {
            if (! isset($rows[$definition->key])) {
                $errors["attributes.{$definition->key}"] = "{$definition->label} is required for this category.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($product, $rows) {
            // One by one, so the search index hears about each removed spec.
            ProductAttribute::query()->where('product_id', $product->id)->whereNotIn('key', array_keys($rows))->get()->each->delete();

            foreach ($rows as $row) {
                ProductAttribute::updateOrCreate(['product_id' => $product->id, 'key' => $row['key']], $row);
            }
        });

        return $product->attributeValues()->get();
    }

    /**
     * Value counts for facet keys over a set of products: [key => [value => count]], most common first.
     *
     * @param  array<int, int>  $productIds
     * @param  array<int, string>|null  $keys  null = every facetable key
     * @return array<string, array<string, int>>
     */
    public function facetCounts(array $productIds, ?array $keys = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = ProductAttribute::query()
            ->whereIn('product_id', $productIds)
            ->where('is_facetable', true)
            ->when($keys !== null, fn ($q) => $q->whereIn('key', $keys))
            ->selectRaw('key, value, count(*) as aggregate')
            ->groupBy('key', 'value')
            ->orderByDesc('aggregate')
            ->get();

        $facets = [];

        foreach ($rows as $row) {
            $facets[$row->key][$row->value] = (int) $row->aggregate;
        }

        return $facets;
    }

    private function check(CategoryAttribute $definition, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return "{$definition->label} must be a single value.";
        }

        $value = trim((string) $value);

        if (mb_strlen($value) > 255) {
            return "{$definition->label} is too long.";
        }

        return match ($definition->type) {
            'number' => is_numeric($value) && (float) $value >= 0 && (float) $value < 1e11 ? null : "{$definition->label} must be a number.",
            'boolean' => in_array(strtolower($value), ['1', '0', 'true', 'false', 'yes', 'no'], true) ? null : "{$definition->label} must be yes or no.",
            'enum' => in_array(mb_strtolower($value), array_map('mb_strtolower', (array) $definition->options), true) ? null : "{$definition->label} must be one of: ".implode(', ', (array) $definition->options).'.',
            default => null,
        };
    }
}
