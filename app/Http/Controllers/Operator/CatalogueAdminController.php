<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\CategoryAttribute;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use App\Services\Operator\FeaturedStores;
use App\Services\Operator\OperatorAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The category tree (name, order, slug, parent, hide, attribute definitions) and the featured stores selection. */
class CatalogueAdminController extends Controller
{
    use AnswersDecisions;

    public function categories(Request $request, CategoryTree $tree): View
    {
        $selected = $request->integer('category') ? StoreCategory::query()->with(['attributeDefinitions' => fn ($q) => $q->orderBy('position')])->find($request->integer('category')) : null;
        $all = StoreCategory::query()->orderBy('path')->get(['id', 'name', 'slug', 'parent_id', 'depth', 'sort_order', 'is_active', 'path']);

        return view('operator.catalogue.categories', [
            'nodes' => $all,
            'counts' => $tree->counts(),
            'selected' => $selected,
        ]);
    }

    public function storeCategory(Request $request, CategoryTree $tree, OperatorAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:store_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:store_categories,slug'],
        ]);

        $category = $tree->create(array_filter(['name' => $data['name'], 'slug' => $data['slug'] ?? null, 'sort_order' => $data['sort_order'] ?? 0], fn ($v) => $v !== null), ! empty($data['parent_id']) ? StoreCategory::query()->find($data['parent_id']) : null);
        $audit->record($request->user(), 'category.create', $category);

        return redirect()->route('operator.catalogue.categories', ['category' => $category->id])->with('status', 'Category created.');
    }

    public function updateCategory(Request $request, StoreCategory $category, CategoryTree $tree, OperatorAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('store_categories', 'slug')->ignore($category->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'parent_id' => ['nullable', 'integer', 'exists:store_categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->attempt('category', fn () => DB::transaction(function () use ($data, $category, $tree, $audit, $request) {
            $parentId = $data['parent_id'] ?? null;
            $before = $category->only(['name', 'slug', 'is_active', 'parent_id']);

            $category->update(['name' => $data['name'], 'slug' => $data['slug'], 'sort_order' => $data['sort_order'] ?? 0, 'is_active' => (bool) ($data['is_active'] ?? false)]);

            if ((int) $parentId !== (int) $category->parent_id) {
                $tree->move($category->refresh(), $parentId ? StoreCategory::query()->findOrFail($parentId) : null);
            }

            CategoryTree::forgetCounts();
            $audit->record($request->user(), 'category.update', $category->refresh(), null, ['was' => $before, 'now' => $category->only(['name', 'slug', 'is_active', 'parent_id'])]);
        }), 'Category saved.', route('operator.catalogue.categories', ['category' => $category->id]));
    }

    public function storeAttribute(Request $request, StoreCategory $category, OperatorAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('category_attributes', 'key')->where('category_id', $category->id)],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:text,number,enum,boolean'],
            'unit' => ['nullable', 'string', 'max:20'],
            'options' => ['nullable', 'string', 'max:1000'],
            'is_facetable' => ['nullable', 'boolean'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $options = $data['type'] === 'enum' ? array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($data['options'] ?? '')) ?: []))) : null;

        if ($data['type'] === 'enum' && count($options ?? []) < 2) {
            return back()->withInput()->withErrors(['options' => 'A choice attribute needs at least two options, separated by commas.']);
        }

        $category->attributeDefinitions()->create([
            'key' => $data['key'], 'label' => $data['label'], 'type' => $data['type'], 'unit' => $data['unit'] ?? null, 'options' => $options,
            'is_facetable' => (bool) ($data['is_facetable'] ?? false), 'is_required' => (bool) ($data['is_required'] ?? false),
            'position' => (int) $category->attributeDefinitions()->max('position') + 1,
        ]);
        $audit->record($request->user(), 'category.attribute', $category, null, ['added' => $data['key']]);

        return back()->with('status', 'Attribute added. Sellers see it on listings in this category and its children.');
    }

    public function destroyAttribute(Request $request, StoreCategory $category, CategoryAttribute $attribute, OperatorAudit $audit): RedirectResponse
    {
        abort_unless($attribute->category_id === $category->id, 404);
        $attribute->delete();
        $audit->record($request->user(), 'category.attribute', $category, null, ['removed' => $attribute->key]);

        return back()->with('status', 'Attribute removed from the definition. Values already saved on listings are kept.');
    }

    public function featured(): View
    {
        $featured = app(FeaturedStores::class);

        return view('operator.catalogue.featured', [
            'featured' => $featured->all(),
            'candidates' => Store::query()->where('is_active', true)->whereNotIn('id', $featured->all()->pluck('store_id'))->orderBy('name')->limit(200)->get(['id', 'name']),
            'limit' => FeaturedStores::LIMIT,
        ]);
    }

    public function addFeatured(Request $request, FeaturedStores $featured): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer', 'exists:stores,id'], 'note' => ['nullable', 'string', 'max:190']]);

        return $this->attempt('featured', fn () => $featured->add(Store::query()->findOrFail($data['store_id']), $request->user(), $data['note'] ?? null), 'Store featured.');
    }

    public function removeFeatured(Request $request, Store $store, FeaturedStores $featured): RedirectResponse
    {
        $featured->remove($store, $request->user());

        return back()->with('status', 'Store removed from the featured list.');
    }
}
