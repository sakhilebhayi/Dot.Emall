<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Models\StoreCategory;
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
    public function index(Request $request): View
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
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        // Counted like the list below: listings a buyer cannot see (pending, rejected, switched off, closed store) do not count.
        $categories = StoreCategory::withCount(['products' => fn ($q) => $q->browsable()])->orderBy('name')->get();

        return view('products.index', [
            'products' => $products,
            'categories' => $categories,
            'search' => $search,
            'category' => $category,
        ]);
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

        $product->load(['store', 'category', 'images', 'reviews.user']);

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
