<?php

namespace App\Services\DeskSearch;

use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Operator\SupportLookup;

/**
 * The search in the seller desk and the operator area (R-52). The shop's own search is a public catalogue search; the desks had none.
 *
 * Both searches are scoped on the server from the SIGNED-IN person, never from anything the request says:
 *   - forSeller() reads only the store the person owns (User::store), and only the parcels and returns addressed to them;
 *   - forOperator() is only ever reached behind the `operator` middleware, and checks the flag again here (defence in depth).
 * Nothing is invented: every row is an existing product, parcel, return, listing, store, order or person, linked to the page that
 * already opens it. Each group is capped, the query is parameterised and its LIKE wildcards are escaped. The people rows reuse
 * SupportLookup, so an e-mail address appears masked, exactly as in the support lookup, and OPENING a person, store or order is
 * still access-logged by that page.
 *
 * @phpstan-type Row array{href: string, label: string, code?: string, tag?: string}
 * @phpstan-type Group array{head: string, rows: list<Row>}
 */
final class DeskSearch
{
    /** Rows per group. */
    public const LIMIT = 5;

    /** A query shorter than this finds nothing; longer than MAX_LENGTH is cut. */
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 60;

    public function __construct(private readonly SupportLookup $lookup) {}

    /** @return list<Group> */
    public function forSeller(User $user, string $query): array
    {
        $store = $user->store;
        $q = $this->clean($query);

        if ($store === null || $q === null) {
            return [];
        }

        $like = '%'.$this->like(mb_strtolower($q)).'%';
        $number = $this->number($q);
        $groups = [];

        $products = Product::query()->where('store_id', $store->id)
            ->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$like]))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'sku', 'status', 'is_active']);
        $groups[] = ['head' => 'Your products', 'rows' => $products->map(fn (Product $p) => $this->row(route('seller.products.edit', $p), $p->name, $p->sku ?: null, $p->is_active ? ucfirst(str_replace('_', ' ', (string) $p->status)) : 'Switched off'))->all()];

        // Parcels: the store's own share of an order, found by the order reference or the buyer's name.
        $parcels = OrderGroup::query()->where('store_id', $store->id)->where('seller_id', $user->id)
            ->where(fn ($w) => $w->when($number !== null, fn ($n) => $n->orWhere('order_id', $number))->orWhereHas('order.user', fn ($u) => $u->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])))
            ->with('order:id,user_id,created_at', 'order.user:id,name')->orderByDesc('id')->limit(self::LIMIT)->get();
        $groups[] = ['head' => 'Your orders', 'rows' => $parcels->map(fn (OrderGroup $g) => $this->row(route('seller.orders.show', $g), 'Order '.$g->order->reference(), null, $g->order->user?->name ?? 'Guest'))->all()];

        if ($number !== null) {
            $returns = ReturnRequest::query()->where('seller_id', $user->id)
                ->where(fn ($w) => $w->where('id', $number)->orWhere('order_id', $number))
                ->orderByDesc('id')->limit(self::LIMIT)->get(['id', 'order_id', 'status']);
            $groups[] = ['head' => 'Your returns', 'rows' => $returns->map(fn (ReturnRequest $r) => $this->row(route('seller.returns.show', $r), 'Return #'.$r->id, null, $r->status->label()))->all()];
        }

        $groups = array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));
        $groups[] = ['head' => 'Not here?', 'rows' => [['href' => route('seller.products.index', ['q' => $q]), 'label' => 'See every product matching "'.$q.'"', 'action' => true]]];

        return $groups;
    }

    /** @return list<Group> */
    public function forOperator(User $user, string $query): array
    {
        $q = $this->clean($query);

        if (! $user->is_platform_operator || $q === null) {
            return [];
        }

        $like = '%'.$this->like(mb_strtolower($q)).'%';
        $number = $this->number($q);
        $groups = [];

        $listings = Product::query()->with('store:id,name')
            ->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$like])->when($number !== null, fn ($n) => $n->orWhere('id', $number)))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'store_id', 'name', 'sku', 'status']);
        $groups[] = ['head' => 'Listings', 'rows' => $listings->map(fn (Product $p) => $this->row(route('operator.products.show', $p), $p->name, $p->sku ?: null, $p->store?->name))->all()];

        $stores = Store::query()->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'status']);
        $groups[] = ['head' => 'Stores', 'rows' => $stores->map(fn (Store $s) => $this->row(route('operator.stores.show', $s), $s->name, null, $s->status->label()))->all()];

        if ($number !== null) {
            $orders = Order::query()->whereKey($number)->limit(1)->get(['id', 'created_at']);
            $groups[] = ['head' => 'Orders', 'rows' => $orders->map(fn (Order $o) => $this->row(route('operator.people.order', $o), 'Order '.$o->reference(), null, $o->created_at->format('j M Y')))->all()];
        }

        $people = $this->lookup->search('user', $q)->take(self::LIMIT);
        $groups[] = ['head' => 'People', 'rows' => $people->map(fn (array $p) => $this->row(route('operator.people.user', $p['id']), $p['title'], null, null))->values()->all()];

        $groups = array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));
        $groups[] = ['head' => 'Not here?', 'rows' => [
            ['href' => route('operator.products.index', ['q' => $q, 'status' => 'all']), 'label' => 'See every listing matching "'.$q.'"', 'action' => true],
            ['href' => route('operator.people.index', ['q' => $q]), 'label' => 'Look up a person, store or order', 'action' => true],
        ]];

        return $groups;
    }

    /** The query, trimmed and cut, or null when it is too short to search. */
    private function clean(string $query): ?string
    {
        $q = mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 0, self::MAX_LENGTH);

        return mb_strlen($q) >= self::MIN_LENGTH ? $q : null;
    }

    /** "#00012", "12" -> 12; anything else null. */
    private function number(string $q): ?int
    {
        $digits = ltrim($q, '#');

        return ctype_digit($digits) && strlen($digits) <= 9 ? (int) $digits : null;
    }

    /** Escapes LIKE wildcards with "!" (declared with ESCAPE '!' in every query: SQLite has no default escape character, MySQL and PostgreSQL treat a backslash differently). */
    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @return array{href: string, label: string, code?: string, tag?: string} */
    private function row(string $href, string $label, ?string $code, ?string $tag): array
    {
        return array_filter(['href' => $href, 'label' => $label, 'code' => $code, 'tag' => $tag], fn ($v) => $v !== null && $v !== '');
    }
}
