<?php

namespace App\Services\Storefront;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Community\ReviewVotes;
use App\Services\Community\StoreMembers;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The reviews block of the product page, from real published reviews only. The distribution, the list (sort, filters, pages),
 * the viewer's own review (held ones included, marked), and whether the viewer may write one, with the reason when they may not.
 */
class ProductReviews
{
    public const SORTS = [
        'helpful' => 'Most helpful',
        'newest' => 'Newest',
        'highest' => 'Highest rated',
        'lowest' => 'Lowest rated',
    ];

    public const PER_PAGE = 6;

    public function __construct(private readonly ReviewService $reviews, private readonly ReviewVotes $votes, private readonly StoreMembers $members) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Product $product, Request $request, ?User $user): array
    {
        $summary = $this->summary($product);
        $sort = array_key_exists((string) $request->query('reviews_sort'), self::SORTS) ? (string) $request->query('reviews_sort') : 'helpful';
        $verifiedOnly = $request->boolean('reviews_verified');
        $withPhotos = $request->boolean('reviews_photos');

        $query = Review::query()->published()->where('product_id', $product->id)
            ->with(['user:id,name', 'photos', 'response' => fn ($q) => $q->where('status', '!=', 'hidden')])
            ->when($verifiedOnly, fn ($q) => $q->where('verified_purchase', true))
            ->when($withPhotos, fn ($q) => $q->whereHas('photos'));

        match ($sort) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('helpful_count')->orderByDesc('id'),
            'lowest' => $query->orderBy('rating')->orderByDesc('helpful_count')->orderByDesc('id'),
            default => $query->orderByDesc('helpful_count')->orderByDesc('id'),
        };

        /** @var LengthAwarePaginator<int, Review> $page */
        $page = $query->paginate(self::PER_PAGE, ['*'], 'reviews_page')->withQueryString()->fragment('reviews');

        $voted = $user ? $this->votes->votedBy($user, $page->getCollection()->pluck('id')->all()) : [];
        $own = $user ? Review::query()->where('product_id', $product->id)->where('user_id', $user->id)->with('photos')->first() : null;
        $canSpeak = $user !== null && $this->members->canSpeakFor($user, $product->store);

        return [
            'summary' => $summary,
            'page' => $page,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'verifiedOnly' => $verifiedOnly,
            'withPhotos' => $withPhotos,
            'voted' => $voted,
            'own' => $own,
            'ownCanEdit' => $own !== null && $this->reviews->canEdit($own),
            'editDays' => (int) config('discovery.reviews.edit_window_days', 30),
            'canSpeakForStore' => $canSpeak,
            'write' => $this->eligibility($product, $user, $own),
            'maxPhotos' => (int) config('discovery.reviews.max_photos', 4),
        ];
    }

    /**
     * Counts per star from the published reviews. Empty (count 0) when there are none, and the view then draws nothing.
     *
     * @return array{count: int, average: float, stars: array<int, int>, verified: int, photos: int}
     */
    public function summary(Product $product): array
    {
        $rows = Review::query()->published()->where('product_id', $product->id)
            ->selectRaw('rating, COUNT(*) as n, SUM(CASE WHEN verified_purchase THEN 1 ELSE 0 END) as verified')
            ->groupBy('rating')->get();

        $stars = array_fill_keys([5, 4, 3, 2, 1], 0);

        foreach ($rows as $row) {
            $stars[(int) $row->rating] = (int) $row->n;
        }

        $count = array_sum($stars);
        $average = $count > 0 ? array_sum(array_map(fn ($s, $n) => $s * $n, array_keys($stars), $stars)) / $count : 0.0;
        $photos = $count > 0
            ? (int) DB::table('review_photos')->join('reviews', 'reviews.id', '=', 'review_photos.review_id')
                ->where('reviews.product_id', $product->id)->where('reviews.status', Review::PUBLISHED)->distinct()->count('reviews.id')
            : 0;

        return ['count' => $count, 'average' => round($average, 1), 'stars' => $stars, 'verified' => (int) $rows->sum('verified'), 'photos' => $photos];
    }

    /**
     * May this person write a review, and if not, why not in words.
     *
     * @return array{state: string, message: ?string}
     */
    private function eligibility(Product $product, ?User $user, ?Review $own): array
    {
        if ($user === null) {
            return ['state' => 'guest', 'message' => 'Reviews come from people who bought this product. Sign in with InfoDot to see whether you can review it.'];
        }

        if ($own !== null) {
            return ['state' => 'reviewed', 'message' => null];
        }

        if ($product->store->owner_id === $user->id) {
            return ['state' => 'own', 'message' => 'This is your own listing, so you cannot review it.'];
        }

        if ($this->reviews->hasPurchased($user, $product)) {
            return ['state' => 'eligible', 'message' => null];
        }

        $open = OrderItem::query()->where('product_id', $product->id)
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')->first(['id', 'order_id']);

        return $open
            ? ['state' => 'waiting', 'message' => 'You can review this once your order has been delivered or collected. It is not there yet.']
            : ['state' => 'not_bought', 'message' => 'Only people whose order for this product was delivered or collected can review it. That is how the verified purchase label stays honest.'];
    }
}
