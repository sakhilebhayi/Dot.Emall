<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Services\Catalogue\CategoryTree;
use App\Services\ReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Buyer-facing browse + detail routes (platform-loop pass). Previously
 * there was no way to reach a product at all except by querying the
 * database directly — the owner dashboard only lists a seller's own
 * products, and there was no marketplace/storefront page for buyers.
 * See wiki.md §7 roadmap — "search integration" was listed but never
 * wired to any controller; this adds a real (if simple) search/filter
 * page against the existing schema, no Scout/Meilisearch dependency.
 */
class ProductController extends Controller
{
    /**
     * Searchable, filterable product marketplace. Only products belonging
     * to an active store, and themselves active, are browsable — see
     * Product::scopeBrowsable().
     */
    public function index(Request $request, CategoryTree $tree): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // Category taxonomy validation (wiki.md §6/§7 gap): a category
            // filter must reference a real StoreCategory row rather than
            // accepting an arbitrary value.
            'category' => ['nullable', 'integer', 'exists:store_categories,id'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $category = $validated['category'] ?? null;

        $products = Product::query()
            ->browsable()
            ->with(['store', 'category', 'images'])
            ->when($search !== '', fn ($q) => $this->whereNameContains($q, $search))
            // A category includes everything beneath it (Electronics shows its laptops and phones).
            ->when($category, fn ($q) => $q->whereIn('category_id', $tree->withDescendants((int) $category)))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        // The category tree's own counts: only listings a buyer can see (pending, rejected, switched off, closed store do not
        // count), a parent counting everything beneath it. Categories with nothing to show are not offered as a filter.
        $categories = $this->categoryOptions($tree, $category !== null ? (int) $category : null);

        return view('products.index', [
            'products' => $products,
            'categories' => $categories,
            'search' => $search,
            'category' => $category,
        ]);
    }

    /**
     * The category facet: the top-level categories with listings, and the children of the one the shopper is in.
     *
     * @return list<array{value: int, label: string, count: int, checked: bool}>
     */
    private function categoryOptions(CategoryTree $tree, ?int $selected): array
    {
        $roots = $tree->tree();
        $selectedRoot = null;

        if ($selected !== null) {
            $rootId = (int) (array_values(array_filter(explode('/', (string) ($tree->find($selected)['path'] ?? ''))))[0] ?? 0);
            $selectedRoot = $rootId ?: null;
        }

        $options = [];

        foreach ($roots as $root) {
            $keep = $root['rollup_count'] > 0 || $root['id'] === $selected;

            if (! $keep) {
                continue;
            }

            $options[] = ['value' => $root['id'], 'label' => $root['name'], 'count' => $root['rollup_count'], 'checked' => $selected === $root['id']];

            if ($root['id'] === $selectedRoot) {
                foreach ($root['children'] as $child) {
                    if ($child['rollup_count'] > 0 || $child['id'] === $selected) {
                        $options[] = ['value' => $child['id'], 'label' => '- '.$child['name'], 'count' => $child['rollup_count'], 'checked' => $selected === $child['id']];
                    }
                }
            }
        }

        return $options;
    }

    /**
     * A name search that behaves the same on every database: case-insensitive (PostgreSQL's LIKE is case sensitive, so
     * "samsung" found nothing in production while SQLite found "Samsung"), and a literal % or _ typed by the buyer is
     * matched as itself instead of acting as a wildcard.
     */
    private function whereNameContains(Builder $query, string $search): Builder
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        return $query->whereRaw("name {$operator} ? escape '\\'", ['%'.$escaped.'%']);
    }

    /**
     * Product detail page: gallery, store info, reviews, add-to-cart form.
     * Inactive products / products under an inactive store 404 for
     * everyone except the owning seller (matches AuctionPolicy's draft
     * handling for the equivalent buyer-facing case).
     */
    public function show(Product $product, ReviewService $reviews): View
    {
        $isOwner = auth()->check() && auth()->id() === $product->store->owner_id;

        abort_unless(
            $isOwner || ($product->is_active && $product->status === 'approved' && $product->store->is_active),
            404
        );

        // Only published reviews are shown: one held for the operator or removed never reaches the page.
        $product->load(['store', 'category', 'images', 'reviews' => fn ($q) => $q->published()->with('user')]);

        $user = auth()->user();

        // A guest who clicks "Sign in to buy" should land back on this page.
        if ($user === null) {
            session(['url.intended' => url()->current()]);
        }

        $hasReviewed = $user !== null && $reviews->hasReviewed($user, $product);
        $canReview = $user !== null && ! $hasReviewed && Gate::forUser($user)->allows('create', [Review::class, $product]);

        return view('products.show', [
            'product' => $product,
            'hasReviewed' => $hasReviewed,
            'canReview' => $canReview,
        ]);
    }
}
