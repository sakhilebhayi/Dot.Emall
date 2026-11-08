<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Models\Store;
use App\Notifications\StoreEnquiryNotification;
use App\Services\Community\StoreMembers;
use App\Services\Community\StoreRatings;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchService;
use App\Services\Storefront\ProductPage;
use App\Services\Storefront\StoreDirectory;
use App\Services\Stores\StoreTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * The public store pages: the directory, one store with its products, and the "write to the store" form. Public fields only.
 */
class StorefrontController extends Controller
{
    public function index(Request $request, StoreDirectory $directory): View
    {
        return view('storefront.stores.index', $directory->index($request) + ['directory' => $directory]);
    }

    public function show(Request $request, Store $store, StoreDirectory $directory, StoreMembers $members, SearchService $search, ProductPage $page, StoreRatings $ratings): View
    {
        $user = $request->user();

        // A closed store is not public. Its own people and the operator can still look at it.
        abort_unless($store->is_active || ($user && ($members->canSpeakFor($user, $store) || $members->isOperator($user))), 404);

        $store->loadMissing('owner:id,infodot_signed_in_at', 'team:id,name,infodot_team_uuid,infodot_verified_at');

        // The products: the search service, scoped to this store whatever the query string says.
        $input = collect($request->query())->only(['q', 'category', 'sort', 'price_min', 'price_max', 'in_stock', 'rating', 'brand', 'attr', 'page'])->all();
        $input['store'] = [$store->id];
        $input['per_page'] = 12;
        $result = $search->search(SearchRequest::fromArray($input));

        $recentReviews = Review::query()->published()
            ->whereIn('product_id', Product::query()->where('store_id', $store->id)->select('id'))
            ->with(['user:id,name', 'product:id,name'])->orderByDesc('id')->limit(4)->get();

        return view('storefront.stores.show', [
            'store' => $store,
            'directory' => $directory,
            'trust' => StoreTrust::for($store),
            'rating' => $ratings->summary($store),
            'methods' => $page->methodFacts($store->shippingMethods()->where('is_active', true)->get()->all()),
            'result' => $result,
            'input' => $input,
            'recentReviews' => $recentReviews,
            'returnDays' => (int) config('emall.returns.window_days', 7),
            'facts' => $directory->facts($store),
            'products' => Product::query()->browsable()->where('store_id', $store->id)->orderBy('name')->get(['id', 'name']),
            'closed' => ! $store->is_active,
            'sorts' => ['relevance' => 'Relevance', 'newest' => 'Newest', 'price_asc' => 'Price, low to high', 'price_desc' => 'Price, high to low', 'rating' => 'Best rated', 'best_selling' => 'Best selling'],
        ]);
    }

    /** A signed-in shopper writes to the store. The store's people are notified; the shopper never sees the store's e-mail address. */
    public function message(Request $request, Store $store, StoreMembers $members): RedirectResponse
    {
        abort_unless($store->is_active, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:1000'],
            'product_id' => ['nullable', 'integer'],
        ], [
            'body.required' => 'Write your message to the store.',
            'body.min' => 'Write at least 10 characters so the store knows what you need.',
            'body.max' => 'Keep it to 1000 characters.',
        ]);

        $user = $request->user();

        if ($members->canSpeakFor($user, $store)) {
            return back()->withErrors(['body' => 'This is your own store. Messages from shoppers arrive in your notifications.'], 'enquiry');
        }

        $product = isset($data['product_id']) ? Product::query()->browsable()->where('store_id', $store->id)->find($data['product_id']) : null;

        Notification::send($members->people($store), new StoreEnquiryNotification($store, $user->publicName(), $user->email, trim(preg_replace('/\s+/', ' ', $data['body']) ?? ''), $product));

        return redirect()->to(route('stores.show', $store).'#contact')->with('status', 'Your message is with '.$store->name.'. They will reply to the e-mail address on your account.');
    }
}
