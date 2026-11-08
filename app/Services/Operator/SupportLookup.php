<?php

namespace App\Services\Operator;

use App\Models\ContentReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Stores\StoreTrust;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Support's read-only view: find a person, a store or an order by id or e-mail, then see everything the marketplace holds about it in
 * one place. It changes nothing. Searching shows names and masked e-mail addresses; OPENING a record shows personal data, so the
 * controller writes an access-log entry (who, what, when, why) for every opening (POPIA).
 */
class SupportLookup
{
    public const PURPOSES = [
        'support' => 'A customer or seller asked for help',
        'dispute' => 'Looking into a dispute or a return',
        'fraud' => 'Checking for fraud or abuse',
        'payment' => 'Matching a payment',
        'other' => 'Another operational reason',
    ];

    public function __construct(private readonly StoreTrust $trust, private readonly LedgerService $ledger) {}

    /**
     * @return Collection<int, array{type: string, id: int, title: string, sub: string}>
     */
    public function search(string $type, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $id = ctype_digit(ltrim($query, '#')) ? (int) ltrim($query, '#') : null;
        $email = str_contains($query, '@') ? mb_strtolower($query) : null;

        return match ($type) {
            'store' => Store::query()->with('owner:id,email')
                ->where(fn ($q) => $q->when($id, fn ($w) => $w->where('id', $id))->when($email, fn ($w) => $w->orWhereHas('owner', fn ($o) => $o->whereRaw('lower(email) = ?', [$email])))->when(! $id && ! $email, fn ($w) => $w->where('name', 'like', '%'.$this->like($query).'%')))
                ->orderBy('name')->limit(25)->get()
                ->map(fn (Store $s) => ['type' => 'store', 'id' => $s->id, 'title' => $s->name, 'sub' => 'Store #'.$s->id.', owner '.$this->mask((string) $s->owner?->email).', '.$s->status->label()]),
            'order' => Order::query()->with('user:id,name,email')
                ->where(fn ($q) => $q->when($id, fn ($w) => $w->where('id', $id))->when($email, fn ($w) => $w->orWhereRaw('lower(guest_email) = ?', [$email])->orWhereHas('user', fn ($u) => $u->whereRaw('lower(email) = ?', [$email]))))
                ->when(! $id && ! $email, fn ($q) => $q->whereRaw('1 = 0'))
                ->orderByDesc('id')->limit(25)->get()
                ->map(fn (Order $o) => ['type' => 'order', 'id' => $o->id, 'title' => 'Order '.$o->reference(), 'sub' => ($o->user?->name ?? 'Guest').', '.$this->mask((string) $o->buyerEmail()).', '.$o->created_at->format('d M Y')]),
            default => User::query()
                ->where(fn ($q) => $q->when($id, fn ($w) => $w->where('id', $id))->when($email, fn ($w) => $w->orWhereRaw('lower(email) = ?', [$email]))->when(! $id && ! $email, fn ($w) => $w->where('name', 'like', '%'.$this->like($query).'%')))
                ->orderBy('name')->limit(25)->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['type' => 'user', 'id' => $u->id, 'title' => $u->name, 'sub' => 'Person #'.$u->id.', '.$this->mask($u->email)]),
        };
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        $orders = Order::query()->where('user_id', $user->id)->withCount('items')->orderByDesc('id')->limit(20)->get();
        $orderIds = Order::query()->where('user_id', $user->id)->pluck('id');

        return [
            'user' => $user,
            'store' => Store::query()->where('owner_id', $user->id)->first(),
            'orders' => $orders,
            'order_total' => $orderIds->count(),
            'returns' => ReturnRequest::query()->where('requester_id', $user->id)->orderByDesc('id')->limit(20)->get(),
            'payments' => Payment::query()->whereIn('order_id', $orderIds)->orderByDesc('id')->limit(20)->get(),
            'reports_filed' => ContentReport::query()->where('reporter_id', $user->id)->orderByDesc('id')->limit(20)->get(),
            'reports_against' => app(OperatorReports::class)->repeatOffenders(365, 1)->first(fn ($r) => $r['user']->id === $user->id),
        ];
    }

    /** @return array<string, mixed> */
    public function store(Store $store): array
    {
        $store->loadMissing('owner:id,name,email');

        return [
            'store' => $store,
            'trust' => $this->trust->for($store),
            'listings' => $store->products()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'orders' => Order::query()->whereIn('id', OrderItem::query()->where('store_id', $store->id)->select('order_id'))->orderByDesc('id')->limit(15)->get(),
            'returns' => ReturnRequest::query()->where('seller_id', $store->owner_id)->orderByDesc('id')->limit(15)->get(),
            'reports' => ContentReport::query()->whereIn('reportable_type', ['store'])->where('reportable_id', $store->id)->orderByDesc('id')->limit(15)->get(),
            'balance' => $this->ledger->balance($store->owner_id),
            'audit' => app(OperatorAudit::class)->query(subjectType: 'store', subjectId: $store->id)->limit(15)->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function order(Order $order): array
    {
        $order->load(['user:id,name,email', 'items', 'groups', 'statusHistory']);

        return [
            'order' => $order,
            'payments' => $order->payments()->with('events')->orderBy('id')->get(),
            'refunds' => Refund::query()->where('order_id', $order->id)->orderBy('id')->get(),
            'returns' => ReturnRequest::query()->where('order_id', $order->id)->orderBy('id')->get(),
            'audit' => app(OperatorAudit::class)->query(subjectType: 'return')->whereIn('subject_id', ReturnRequest::query()->where('order_id', $order->id)->select('id'))->limit(15)->get(),
        ];
    }

    /** "ayesha@example.com" becomes "a****@example.com": enough to tell two people apart, not enough to copy. */
    public function mask(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email === '' ? 'no e-mail' : $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).str_repeat('*', max(2, min(6, Str::length($local) - 1))).'@'.$domain;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
