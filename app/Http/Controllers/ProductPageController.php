<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Community\ProductQuestions;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\Recommender;
use App\Services\Discovery\Track;
use App\Services\Storefront\ProductPage;
use App\Services\Storefront\ProductReviews;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public product page (route products.show, reached through ProductController::show). All decisions live in the
 * Storefront services; this class gathers them, records the view, and answers either the whole page or, for the script that
 * refreshes the buy box when a choice changes, just the buy box.
 */
class ProductPageController extends Controller
{
    /** Query keys that mean "the same visit, a different view of it": they must not count as another product view. */
    private const NOT_A_NEW_VIEW = ['opt', 'variant', 'reviews_sort', 'reviews_verified', 'reviews_photos', 'reviews_page'];

    public function show(Request $request, Product $product, ProductPage $page, ProductReviews $reviews, ProductQuestions $questions, Recommender $recommender, RecentlyViewed $viewed): View|Response
    {
        $product->loadMissing('store');
        $user = $request->user();

        abort_unless($page->visibleTo($product, $user), 404);

        $buy = $page->buy($product, $request, $user);

        if ($request->header('X-PDP-Fragment') === 'buy') {
            return response(view('pdp.partials.buy', $buy)->render())->header('Vary', 'X-PDP-Fragment')->header('Cache-Control', 'no-store');
        }

        if ($user === null) {
            // A guest who signs in from this page (to save it, ask or review) comes back to it.
            session(['url.intended' => url()->current()]);
        }

        $details = $page->details($product);
        $actor = Actor::forRequest($request);

        if ($buy['isLive'] && $this->isANewView($request)) {
            $viewed->view($actor, $product, $this->source($request));
        }

        $sets = $buy['isLive'] ? $recommender->forProduct($product, $actor) : [];
        $recent = $buy['isLive'] ? $recommender->recentlyViewed($actor, $product->id, 8) : null;

        return view('products.show', [
            ...$buy,
            ...$details,
            'reviewData' => $reviews->for($product, $request, $user),
            'questions' => $questions->forProduct($product, $user),
            'sets' => $sets,
            'recent' => $recent,
            'preview' => $page->previewState($product),
            'jsonLd' => $buy['isLive'] ? $page->structuredData($product, $buy) : null,
            'images' => $product->images,
        ]);
    }

    private function isANewView(Request $request): bool
    {
        if (! $request->isMethod('GET') || preg_match('/bot|crawl|spider|slurp|preview|monitor/i', (string) $request->userAgent()) === 1) {
            return false;
        }

        return array_intersect($request->query->keys(), self::NOT_A_NEW_VIEW) === [];
    }

    private function source(Request $request): string
    {
        $src = (string) $request->query('src', 'direct');

        return in_array($src, Track::SOURCES, true) ? $src : 'direct';
    }
}
