<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Discovery\SearchController;
use App\Models\Product;
use App\Services\Community\ProductQuestions;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\Recommender;
use App\Services\Storefront\ProductPage;
use App\Services\Storefront\ProductReviews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * The shop's front door is the search page: the same controller answers /search, /products and the category pages
     * (Discovery\SearchController), with filters, facets, sorts and "did you mean". Only approved listings from active
     * stores appear, see Product::scopeBrowsable() and the search engine's visibility rules.
     */
    public function index(Request $request): View
    {
        return app(SearchController::class)->index($request);
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
     * Product detail page. The page itself is built by ProductPageController and the Storefront services (R-43 W4); this stays
     * as the target of the products.show route so the route table and every link keep working.
     */
    public function show(Request $request, Product $product): View|Response
    {
        return app(ProductPageController::class)->show(
            $request,
            $product,
            app(ProductPage::class),
            app(ProductReviews::class),
            app(ProductQuestions::class),
            app(Recommender::class),
            app(RecentlyViewed::class),
        );
    }
}
