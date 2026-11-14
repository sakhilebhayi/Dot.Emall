<?php

namespace App\Services\Dashboard;

use App\Enums\ReturnStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ReturnRequest;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Discovery\Wishlists;
use App\Services\Operator\NeedsAPerson;
use App\Services\Orders\OrderPage;
use App\Support\Landing;

/**
 * What the buyer's dashboard shows (R-51). ONE class feeds both routes: `dashboard` (for a person with no store) and `account.home`
 * (the account overview), so they cannot drift. Every query is for this person (user_id / requester_id), except the newest listings,
 * which are the public catalogue.
 *
 *   primary action, first match: an unpaid order -> "See the unpaid order"; otherwise the R-50 landing rule (Landing::buyerFallback):
 *   something listed -> "Shop the mall", nothing listed -> "Open a shop".
 */
class BuyerHome
{
    public function __construct(private readonly OrderPage $page, private readonly Wishlists $wishlists, private readonly NeedsAPerson $operatorQueue) {}

    /** @return array<string, mixed> */
    public function data(User $user): array
    {
        $shelf = Product::query()->browsable()->with(['images', 'store:id,name'])->latest('id')->limit(3)->get();
        $orders = Order::query()->where('user_id', $user->id)->with(['items', 'groups'])->latest('id')->limit(5)->get();
        $awaiting = Order::query()->where('user_id', $user->id)->where('requires_payment', true)
            ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])->where('status', '!=', 'cancelled')->count();
        $needing = ReturnRequest::query()->where('requester_id', $user->id)->whereIn('status', [ReturnStatus::Declined->value, ReturnStatus::Approved->value])->with('item')->latest('id')->limit(4)->get();
        $openReturns = ReturnRequest::query()->where('requester_id', $user->id)->whereIn('status', [ReturnStatus::Requested->value, ReturnStatus::Approved->value, ReturnStatus::Received->value, ReturnStatus::Escalated->value])->count();
        $orderCount = Order::query()->where('user_id', $user->id)->count();
        $open = $shelf->isNotEmpty();
        $isOperator = (bool) $user->is_platform_operator;
        $addresses = Address::query()->where('user_id', $user->id)->count();
        $company = Landing::company($user);

        return Landing::common($user) + [
            'shelf' => $shelf,
            'orders' => $orders,
            'orderCount' => $orderCount,
            'statuses' => $orders->mapWithKeys(fn (Order $o) => [$o->id => $this->page->status($o, $o->items)['key']]),
            'awaiting' => $awaiting,
            'returnsNeedingYou' => $needing,
            'openReturns' => $openReturns,
            'wishlist' => $this->wishlists->items($user)->take(3),
            'alerts' => ProductAlert::query()->where('user_id', $user->id)->where('active', true)->count(),
            'searches' => SavedSearch::query()->where('user_id', $user->id)->count(),
            'addressCount' => $addresses,
            'defaultAddress' => Address::query()->where('user_id', $user->id)->where('is_default_shipping', true)->first(),
            'unread' => $user->unreadNotifications()->count(),
            'sells' => $user->stores()->exists(),
            'isOperator' => $isOperator,
            'operatorTotal' => $isOperator ? $this->operatorQueue->total() : 0,
            'open' => $open,
            'headline' => self::headline($awaiting, $needing->count(), $open, $orderCount),
            'sentence' => self::sentence($awaiting, $needing->count(), $open, $company),
            'primary' => self::primary($awaiting, $open),
            'foot' => [Plural::of($orderCount, 'order'), Plural::of($openReturns, 'return').' open', Plural::of($addresses, 'saved address', 'saved addresses')],
        ];
    }

    public static function headline(int $awaiting, int $returnsNeedingYou, bool $open, int $orderCount): string
    {
        return match (true) {
            $awaiting > 0 => Plural::of($awaiting, 'order').' '.Plural::verb($awaiting).' waiting for payment.',
            $returnsNeedingYou > 0 => $returnsNeedingYou === 1 ? '1 return needs your next step.' : $returnsNeedingYou.' returns need your next step.',
            ! $open => 'The shelves are still empty.',
            $orderCount === 0 => 'Your orders will show up here.',
            default => 'Your orders are up to date.',
        };
    }

    public static function sentence(int $awaiting, int $returnsNeedingYou, bool $open, ?string $company): string
    {
        return match (true) {
            $awaiting > 0 => 'Unpaid orders are cancelled after a short time and the items go back on sale.',
            $returnsNeedingYou > 0 => 'A return the seller approved or declined waits for you below.',
            ! $open => 'Dot.Emall is the marketplace of the Dot ecosystem and no product is listed yet. Shops come first: if '.($company ?: 'your business').' has something to sell, it can be one of them.',
            default => 'Every label shows the price, what delivery costs to your address and the total, before you pay.',
        };
    }

    /** @return array{label: string, href: string, route: string, state: string} */
    public static function primary(int $awaiting, bool $open): array
    {
        if ($awaiting > 0) {
            return ['label' => $awaiting === 1 ? 'See the unpaid order' : 'See the unpaid orders', 'href' => route('orders.index', ['status' => 'awaiting_payment']), 'route' => 'orders.index', 'state' => 'pay'];
        }

        return Landing::buyerFallback($open);
    }
}
