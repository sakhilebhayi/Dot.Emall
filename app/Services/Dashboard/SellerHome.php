<?php

namespace App\Services\Dashboard;

use App\Enums\StoreStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Seller\SellerAnalytics;
use App\Services\Seller\SellerDesk;
use App\Support\Landing;
use App\Support\Shop;

/**
 * What the seller's dashboard shows (R-51). ONE class feeds both routes that used to be two pages: `dashboard` (the landing a person arrives on)
 * and `seller.dashboard` (the desk home), so they cannot drift. Everything is read for ONE store, by its id, from the services the desk already
 * uses; the headline and the primary action are chosen from those counts.
 *
 *   primary action, first match:  parcels to ship -> "Open orders to ship";  otherwise the R-50 landing rule (Landing::sellerFallback):
 *   no product yet -> "Add your first product", products -> "Manage your products".
 */
class SellerHome
{
    public function __construct(
        private readonly SellerDesk $desk,
        private readonly SellerAnalytics $analytics,
        private readonly LedgerService $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function data(User $user, Store $store, int|string|null $period = null): array
    {
        $window = $this->analytics->window($period);
        $needs = $this->desk->needsYou($store, $user);
        $sales = $this->analytics->sales($store, $window['from'], $window['to']);
        $productCount = Product::query()->where('store_id', $store->id)->count();
        $balance = $this->ledger->balance($user);

        $sampleIds = $needs['lowStockSample']->pluck('product_id')->merge($needs['rejected']->pluck('id'))->filter()->unique()->values()->all();

        return Landing::common($user, $store->name) + [
            'store' => $store,
            'needs' => $needs,
            'window' => $window,
            'periods' => SellerAnalytics::PERIODS,
            'sales' => $sales,
            'previous' => $this->analytics->sales($store, $window['prevFrom'], $window['prevTo']),
            'chart' => SalesChart::build($this->analytics->series($store, $window['from'], $window['to'])),
            'fulfilment' => $this->analytics->fulfilment($store, $window['from'], $window['to']),
            'balance' => $balance,
            'ledgerCurrency' => Shop::currency(),
            'recent' => $this->desk->recentParcels($store),
            'reviews' => $this->desk->reviewSummary($store),
            'responses' => $this->desk->responseTimes($store),
            'productCount' => $productCount,
            'thumbs' => self::thumbs($store, $sampleIds),
            'headline' => self::headline($store, $needs, $productCount),
            'sentence' => self::sentence($store, $needs, $productCount),
            'primary' => self::primary($needs, $productCount),
            'foot' => self::foot($store, $needs, $productCount),
        ];
    }

    /** First photograph (the cover) of each listed product of this store: id => thumbnail URL. @return array<int, string> */
    public static function thumbs(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductImage::query()
            ->whereIn('product_id', Product::withTrashed()->where('store_id', $store->id)->whereIn('id', $productIds)->select('id'))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->unique('product_id')->mapWithKeys(fn (ProductImage $i) => [$i->product_id => $i->cardUrl()])->all();
    }

    private static function open(Store $store): bool
    {
        return $store->status === StoreStatus::Active && $store->is_active;
    }

    public static function headline(Store $store, array $needs, int $productCount): string
    {
        return match (true) {
            ! self::open($store) => 'Your shop is not open to shoppers yet.',
            $needs['toShip'] > 0 => Plural::of($needs['toShip'], 'parcel').' '.Plural::verb($needs['toShip']).' waiting for you to pack and ship.',
            $needs['total'] > 0 => $needs['total'] === 1 ? '1 thing needs you today.' : $needs['total'].' things need you today.',
            $productCount === 0 => 'Your shop has nothing listed yet.',
            default => 'Nothing needs you today.',
        };
    }

    /** The sentence under the headline: what else is waiting, or why the shop is quiet. */
    public static function sentence(Store $store, array $needs, int $productCount): string
    {
        if (! self::open($store)) {
            return match (true) {
                $store->status === StoreStatus::PendingReview => $store->name.' is waiting for the Emall team to review it'.($productCount === 0 ? ', and has no product listed' : '').'. Shoppers cannot see it until it is reviewed.',
                $store->status === StoreStatus::Suspended => $store->name.' is suspended and closed to shoppers.',
                default => $store->name.' is closed to shoppers'.($productCount === 0 ? ' and has no product listed' : '').'. Finish the checklist in your store profile to open it.',
            };
        }

        $rest = [];
        if ($needs['returns']) {
            $rest[] = Plural::of($needs['returns'], 'return').' to answer';
        }
        if ($needs['lowStock']) {
            $rest[] = Plural::of($needs['lowStock'], 'product').' at low stock';
        }
        if ($needs['questions']) {
            $rest[] = Plural::of($needs['questions'], 'question').' to answer';
        }
        if ($needs['reviews']) {
            $rest[] = Plural::of($needs['reviews'], 'review').' without a reply';
        }
        if ($needs['rejected']->count()) {
            $rest[] = Plural::of($needs['rejected']->count(), 'rejected listing');
        }

        // When nothing is to ship the headline already counts the total, so the sentence names the parts instead of repeating them.
        if ($needs['toShip'] === 0 && $needs['total'] > 0 && $rest !== []) {
            return 'That is '.self::join($rest).'.';
        }

        if ($rest !== []) {
            return 'Also '.self::join($rest).'.';
        }

        return $productCount === 0
            ? 'Your listings will show here with their real price and stock. A listing starts with a name, a price, your stock and a photograph.'
            : 'Orders to ship, returns, low stock, questions and reviews appear here the moment they need you. Sales are below.';
    }

    /** @return array{label: string, href: string, route: string, state: string} */
    public static function primary(array $needs, int $productCount): array
    {
        if ($needs['toShip'] > 0) {
            return ['label' => 'Open orders to ship', 'href' => route('seller.orders.index', ['tab' => 'to_ship']), 'route' => 'seller.orders.index', 'state' => 'ship'];
        }

        return Landing::sellerFallback($productCount > 0);
    }

    /** Live counts only, for the foot line. @return list<string> */
    public static function foot(Store $store, array $needs, int $productCount): array
    {
        $state = match (true) {
            $store->status === StoreStatus::PendingReview => 'store waiting for review',
            $store->status === StoreStatus::Suspended => 'store suspended',
            $store->status === StoreStatus::Draft => 'store is a draft',
            ! $store->is_active => 'store closed',
            default => 'store open to shoppers',
        };

        return [Plural::of($productCount, 'product'), Plural::of($needs['toShip'], 'parcel').' to pack and ship', $state];
    }

    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }
}
