<?php

namespace App\Services\Account;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Actor;
use App\Support\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Jetstream\Contracts\DeletesTeams;

/**
 * "Delete my account" (R-43 D-7, D-98). A person leaving must never take other people's records, or the money trail, with
 * them: orders, order lines, returns, refunds, payments and the seller ledger belong to the shop's books and to the other
 * side of every sale. What leaves is the person.
 *
 *  1. While the person has something open the account cannot be deleted at all, with every reason listed (obstacles()):
 *     an order that has not reached the buyer (or is still awaiting payment), an open return, a refund not yet paid back;
 *     and for a seller, any parcel still to pack, post or hand over, a return waiting for their answer, a refund owed on one
 *     of their orders, or earnings still HELD in the ledger (sellers settle first: held money is released seven days after
 *     delivery). Earnings already released do not block (payouts are arranged with the Emall team).
 *  2. Otherwise the account is ANONYMISED, not deleted: the name becomes "Former customer", the e-mail a unique address on
 *     the reserved, unresolvable `.invalid` domain (so signing in with InfoDot never finds this row again), the password a
 *     random one nobody knows. Profile photo, API tokens, browser sessions, two-factor secrets, team memberships (and the
 *     teams they own), notifications, addresses, wishlists, saved searches, alerts, notification choices, cart and recently
 *     viewed are removed. A seller's store is switched off, its listings archived, its logo, banner and contact details
 *     removed. Orders (with the delivery address SNAPSHOT taken at checkout), returns, refunds, payments, the ledger,
 *     reviews, questions and answers STAY, attached to the same user row, which now reads "Former customer".
 *
 * Kept on purpose, and an owner decision to revisit with a lawyer: the order's delivery name, phone and address snapshot
 * (needed for the sellers' and the shop's records of the sale; it is not shown to anyone but the people on that order).
 *
 * The foreign keys from those records to users are RESTRICT (migration 2026_11_11_000001), so any other code path that
 * tries to DELETE such a user fails loudly instead of cascading.
 */
class AccountEraser
{
    public function __construct(private readonly DeletesTeams $deletesTeams, private readonly LedgerService $ledger) {}

    /** The reserved TLD `.invalid` can never resolve (RFC 2606), so a placeholder address can never receive mail. */
    public const PLACEHOLDER_DOMAIN = 'deleted.invalid';

    /** At most this many references are named per reason, the rest are counted. */
    private const SHOWN = 3;

    /**
     * Why this person cannot leave yet; empty when they can.
     *
     * @return array<int, string>
     */
    public function obstacles(User $user): array
    {
        $id = $user->getKey();
        $reasons = [];
        $open = [OrderStatus::Pending->value, OrderStatus::Processing->value, OrderStatus::Shipped->value, OrderStatus::ReadyForCollection->value];

        // As a buyer: an order that has not reached them. Cancelled orders and orders delivered, collected or refunded are finished.
        $unfinished = Order::query()->where('user_id', $id)
            ->whereHas('items', fn ($lines) => $lines->whereIn('status', $open))
            ->orderBy('id')->get();
        $unpaid = $unfinished->filter(fn (Order $o) => $o->isAwaitingPayment());
        $onTheWay = $unfinished->reject(fn (Order $o) => $o->isAwaitingPayment());

        if ($unpaid->isNotEmpty()) {
            $reasons[] = ($unpaid->count() > 1 ? 'Orders ' : 'Order ').$this->names($unpaid->map->reference()).($unpaid->count() > 1 ? ' are waiting for payment. Pay for them, or cancel them, first.' : ' is waiting for payment. Pay for it, or cancel it, first.');
        }
        if ($onTheWay->isNotEmpty()) {
            $reasons[] = ($onTheWay->count() > 1 ? 'Orders ' : 'Order ').$this->names($onTheWay->map->reference()).($onTheWay->count() > 1
                ? ' have not reached you yet. Wait until they are delivered or collected, or cancel them while the seller has not sent them.'
                : ' has not reached you yet. Wait until it is delivered or collected, or cancel it while the seller has not sent it.');
        }

        $buyerReturns = ReturnRequest::query()->where('requester_id', $id)->whereIn('status', $this->openReturns())->with('item')->orderBy('id')->get();
        if ($buyerReturns->isNotEmpty()) {
            $reasons[] = ($buyerReturns->count() > 1 ? 'Your returns of ' : 'Your return of ').$this->names($buyerReturns->map(fn (ReturnRequest $r) => $r->item?->displayName() ?? 'an item')).($buyerReturns->count() > 1 ? ' are still open. Wait until they are settled.' : ' is still open. Wait until it is settled.');
        }

        $owed = [RefundStatus::Pending->value, RefundStatus::Submitted->value, RefundStatus::Failed->value];
        $buyerRefunds = Refund::query()->whereIn('status', $owed)->whereIn('order_id', Order::query()->where('user_id', $id)->select('id'))->with('order')->orderBy('id')->get();
        if ($buyerRefunds->isNotEmpty()) {
            $reasons[] = 'A refund on order '.$this->names($buyerRefunds->map(fn (Refund $r) => $r->order?->reference() ?? '#'.$r->order_id)->unique()->values()).' has not been paid back yet. Wait until it arrives.';
        }

        // As a seller.
        $parcels = OrderItem::query()->where('seller_id', $id)->whereIn('status', $open)->with('order')->get()->pluck('order')->filter()->unique('id')->sortBy('id');
        if ($parcels->isNotEmpty()) {
            $reasons[] = 'Your store still has parcels open on order '.$this->names($parcels->map->reference()).'. Pack, send and deliver them, or cancel them, first.';
        }

        $sellerReturns = ReturnRequest::query()->where('seller_id', $id)->whereIn('status', $this->openReturns())->with('item')->orderBy('id')->get();
        if ($sellerReturns->isNotEmpty()) {
            $reasons[] = 'A return of '.$this->names($sellerReturns->map(fn (ReturnRequest $r) => $r->item?->displayName() ?? 'an item')).' on one of your orders is still open. Answer it and wait until it is settled.';
        }

        $sellerRefunds = Refund::query()->whereIn('status', $owed)->whereIn('order_id', OrderItem::query()->where('seller_id', $id)->select('order_id'))->with('order')->orderBy('id')->get();
        if ($sellerRefunds->isNotEmpty()) {
            $reasons[] = 'A refund on your order '.$this->names($sellerRefunds->map(fn (Refund $r) => $r->order?->reference() ?? '#'.$r->order_id)->unique()->values()).' has not been paid back yet. Wait until it is.';
        }

        $held = collect($this->ledger->balance($user))->filter(fn (array $b) => (int) $b['held'] !== 0);
        if ($held->isNotEmpty()) {
            $reasons[] = 'Your store still has earnings held in the ledger ('.$held->map(fn (array $b, string $code) => Shop::money($b['held'], $code))->implode(', ').'), waiting out the return window. They are released seven days after delivery: close your account after that.';
        }

        return $reasons;
    }

    /**
     * Anonymise the person, or throw AccountDeletionBlocked. Checked again under a lock on the user row.
     *
     * @throws AccountDeletionBlocked
     */
    public function erase(User $user): void
    {
        $files = [];

        DB::transaction(function () use ($user, &$files) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $reasons = $this->obstacles($locked);
            if ($reasons !== []) {
                throw new AccountDeletionBlocked($reasons);
            }

            // A seller's store goes quiet; the row, its orders and its ledger stay.
            foreach ($locked->stores as $store) {
                // Archived through the model so the listing photos are removed from the disk too.
                Product::query()->where('store_id', $store->id)->get()->each->delete();

                $files = array_merge($files, array_filter([$store->logo_path, $store->banner_path]));
                $store->forceFill(['is_active' => false, 'logo_path' => null, 'banner_path' => null, 'contact_email' => null, 'contact_phone' => null, 'team_id' => null])->save();
            }

            $locked->teams()->detach();
            $locked->ownedTeams->each(fn (Team $team) => $this->deletesTeams->delete($team));

            $locked->deleteProfilePhoto();
            $locked->tokens()->delete();
            $locked->notifications()->delete();

            // The person's own private rows.
            foreach (['addresses', 'saved_searches', 'product_alerts', 'notification_preferences'] as $table) {
                DB::table($table)->where('user_id', $locked->id)->delete();
            }
            $wishlists = DB::table('wishlists')->where('user_id', $locked->id)->pluck('id');
            DB::table('wishlist_items')->whereIn('wishlist_id', $wishlists)->delete();
            DB::table('wishlists')->whereIn('id', $wishlists)->delete();
            CartItem::withoutGlobalScopes()->where('user_id', $locked->id)->delete();

            // What they looked at and searched for is kept under a code: the code is computed from the id, so it can go too.
            $hash = Actor::forUser($locked)->hash();
            if ($hash !== null) {
                DB::table('product_views')->where('actor_hash', $hash)->delete();
                DB::table('search_queries')->where('actor_hash', $hash)->delete();
            }

            // A message they sent a seller keeps its text; the name and e-mail beside it go.
            DB::table('order_messages')->where('sender_id', $locked->id)->update(['sender_name' => User::FORMER_NAME, 'sender_email' => self::placeholderEmail($locked)]);

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }

            $locked->forceFill([
                'name' => User::FORMER_NAME,
                'email' => self::placeholderEmail($locked),
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'profile_photo_path' => null,
                'current_team_id' => null,
                'is_platform_operator' => false,
                'infodot_signed_in_at' => null,
            ])->save();
        });

        // After the commit: files are not part of the transaction.
        foreach ($files as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    public static function placeholderEmail(User $user): string
    {
        return 'former-customer-'.$user->getKey().'-'.Str::lower((string) Str::ulid()).'@'.self::PLACEHOLDER_DOMAIN;
    }

    /** @return array<int, string> */
    private function openReturns(): array
    {
        return array_map(fn (ReturnStatus $s) => $s->value, array_filter(ReturnStatus::cases(), fn (ReturnStatus $s) => $s->isOpen()));
    }

    /** @param Collection<int, string> $names */
    private function names($names): string
    {
        $shown = $names->take(self::SHOWN)->implode(', ');
        $more = $names->count() - self::SHOWN;

        return $more > 0 ? $shown." and {$more} more" : $shown;
    }
}
