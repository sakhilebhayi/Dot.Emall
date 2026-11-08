<?php

namespace App\Services\Returns;

use App\Enums\OrderStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Catalogue\Inventory;
use App\Services\Notifications\OrderNotifier;
use App\Services\OrderLifecycle;
use App\Services\RefundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns (D-6), adopted from ECTA s44 as a standard, not a legal claim.
 *
 *   requested -> approved | declined | escalated(no answer in respond_by)
 *   declined  -> escalated (the buyer disputes)          -> operator: approved | closed
 *   approved  -> received (seller confirms the goods are back; refund + optional restock happen here)
 *   received  -> refunded
 *
 * A request is possible within 7 days of the line being delivered or collected, once per line. A decline must carry a
 * reason. An unanswered request escalates to the operator queue after 10 days (escalateOverdue(), scheduled). The refund goes
 * through RefundService like every refund: a record first, Paystack after commit, "refunded" only when money is confirmed.
 * Delivery fees are not refunded by a return.
 */
class ReturnService
{
    public function __construct(
        private readonly OrderLifecycle $lifecycle,
        private readonly RefundService $refunds,
        private readonly Inventory $inventory,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * Why a line cannot be returned right now, in words for the buyer; null when it can.
     */
    public function ineligibleBecause(OrderItem $item): ?string
    {
        $item->loadMissing('order');

        if (! $item->status->isCompleted()) {
            return $item->status === OrderStatus::Refunded
                ? 'This item has already been refunded.'
                : 'You can ask for a return once the item has been delivered or collected.';
        }

        $window = (int) config('emall.returns.window_days', 7);
        $since = $item->status === OrderStatus::Collected ? ($item->collected_at ?? $item->delivered_at) : ($item->delivered_at ?? $item->collected_at);

        if ($since === null || $since->copy()->addDays($window)->isPast()) {
            return "The {$window}-day return window has closed for this item.";
        }

        $existing = ReturnRequest::query()->where('order_item_id', $item->id)->where('status', '!=', ReturnStatus::Closed->value)->exists();

        if ($existing) {
            return 'A return has already been requested for this item.';
        }

        return null;
    }

    /**
     * The caller has already checked that $requester owns the order (or holds a guest order link and passes null).
     *
     * @param  array<int, string>  $photoPaths  paths already stored for this return (no upload happens here)
     *
     * @throws ValidationException
     */
    public function request(OrderItem $item, ?User $requester, string $reasonCategory, ?string $note = null, array $photoPaths = []): ReturnRequest
    {
        $reason = ReturnReason::tryFrom($reasonCategory)
            ?? throw ValidationException::withMessages(['reason_category' => 'Choose a reason.']);

        $note = $note !== null ? trim($note) : null;

        if ($reason === ReturnReason::Other && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Tell the seller what is wrong.']);
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'Keep it under 2000 characters.']);
        }

        return DB::transaction(function () use ($item, $requester, $reason, $note, $photoPaths) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($requester !== null && $order->user_id !== $requester->id) {
                throw new AuthorizationException('Only the buyer can ask to return an item.');
            }

            if ($requester === null && $order->user_id !== null) {
                throw new AuthorizationException('This order belongs to an account.');
            }

            if (($why = $this->ineligibleBecause($line)) !== null) {
                throw ValidationException::withMessages(['order_item' => $why]);
            }

            $delivered = $line->status === OrderStatus::Collected ? ($line->collected_at ?? $line->delivered_at) : ($line->delivered_at ?? $line->collected_at);

            $paths = collect($photoPaths)->filter(fn ($p) => is_string($p) && str_starts_with($p, "returns/{$order->id}/") && ! str_contains($p, '..'))->take(5)->values()->all();

            $return = ReturnRequest::create([
                'order_item_id' => $line->id,
                'order_id' => $order->id,
                'requester_id' => $requester?->id,
                'requester_email' => $order->buyerEmail(),
                'seller_id' => $line->seller_id,
                'reason_category' => $reason,
                'note' => $note,
                'photo_paths' => $paths ?: null,
                'status' => ReturnStatus::Requested,
                'refund_cents' => $line->netCents(),
                'delivered_at' => $delivered,
                'respond_by' => now()->addDays((int) config('emall.returns.seller_response_days', 10)),
            ]);

            DB::afterCommit(fn () => $this->notifier->returnRequested($return->load('item')));

            return $return;
        });
    }

    public function approve(ReturnRequest $return, User $actor, ?string $note = null): ReturnRequest
    {
        return $this->decide($return, $actor, ReturnStatus::Approved, $note, [ReturnStatus::Requested, ReturnStatus::Escalated], 'return_approved');
    }

    /**
     * @throws ValidationException when no reason is given
     */
    public function decline(ReturnRequest $return, User $actor, string $reason): ReturnRequest
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Tell the buyer why the return is declined.']);
        }

        return $this->decide($return, $actor, ReturnStatus::Declined, $reason, [ReturnStatus::Requested], 'return_declined');
    }

    /**
     * The seller confirms the goods are back. In one transaction: the line is refunded (a refund record for what was paid, the
     * seller's ledger reversed, the line moved to refunded) and, if asked, the units go back on the shelf. Paystack is asked
     * after the commit.
     */
    public function markReceived(ReturnRequest $return, User $actor, bool $restock = false): ReturnRequest
    {
        $return = DB::transaction(function () use ($return, $actor, $restock) {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $locked = ReturnRequest::query()->lockForUpdate()->with('item')->findOrFail($return->id);

            $this->assertMay($locked, $actor);

            if ($locked->status !== ReturnStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Only an approved return can be marked received.']);
            }

            $locked->update(['status' => ReturnStatus::Received, 'received_at' => now(), 'restock' => $restock]);

            $refund = $this->refunds->refundReturn($locked);
            $this->lifecycle->markRefunded($locked->item, $actor, "Return #{$locked->id} received");

            if ($restock) {
                $variantId = $locked->item->variant_id ?? ProductVariant::query()->where('product_id', $locked->item->product_id)->orderByDesc('is_default')->orderBy('id')->value('id');

                if ($variantId !== null) {
                    $this->inventory->give((int) $variantId, $locked->item->quantity, 'return', $actor->id, $order->id, $locked->item->id, "Return #{$locked->id}");
                }
            }

            $locked->update(['status' => ReturnStatus::Refunded, 'refunded_at' => now(), 'refund_id' => $refund?->id]);

            return $locked;
        });

        DB::afterCommit(function () use ($return) {
            $this->refunds->submitPending($return->order_id);
            $this->notifier->returnDecision($return->load('item', 'order'), 'return_received');
        });

        return $return->refresh();
    }

    /**
     * The buyer disputes a decline.
     */
    public function escalate(ReturnRequest $return, User $buyer, ?string $note = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $buyer, $note) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->requester_id !== $buyer->id) {
                throw new AuthorizationException('Only the buyer can escalate a return.');
            }

            if ($locked->status !== ReturnStatus::Declined) {
                throw ValidationException::withMessages(['status' => 'Only a declined return can be escalated.']);
            }

            $locked->update([
                'status' => ReturnStatus::Escalated,
                'escalated_at' => now(),
                'escalation_reason' => 'buyer_disputed_decline',
                'escalation_note' => $note !== null ? mb_substr(trim($note), 0, 2000) : null,
            ]);

            DB::afterCommit(fn () => $this->notifier->returnEscalated($locked->load('item', 'order')));

            return $locked;
        });
    }

    /**
     * The scheduled job: every unanswered request past its deadline goes to the operator queue. Idempotent and safe to
     * overlap (the update is guarded on the status).
     *
     * @return int how many were escalated
     */
    public function escalateOverdue(): int
    {
        $count = 0;

        ReturnRequest::query()
            ->where('status', ReturnStatus::Requested->value)->where('respond_by', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($returns) use (&$count) {
                foreach ($returns as $return) {
                    $updated = ReturnRequest::query()->whereKey($return->id)->where('status', ReturnStatus::Requested->value)->update([
                        'status' => ReturnStatus::Escalated->value,
                        'escalated_at' => now(),
                        'escalation_reason' => 'seller_no_response',
                        'updated_at' => now(),
                    ]);

                    if ($updated === 1) {
                        $count++;
                        $this->notifier->returnEscalated($return->refresh()->load('item', 'order'));
                    }
                }
            });

        return $count;
    }

    /**
     * An operator settles an escalated return: `approve` lets it proceed (the seller still marks the goods received, or the
     * operator does), `uphold_decline` closes it.
     */
    public function operatorResolve(ReturnRequest $return, User $operator, string $decision, string $note): ReturnRequest
    {
        if (! $operator->is_platform_operator) {
            throw new AuthorizationException('Only the Emall team can settle an escalated return.');
        }

        if (mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['note' => 'Write down why, so both sides can read it.']);
        }

        return DB::transaction(function () use ($return, $operator, $decision, $note) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->status !== ReturnStatus::Escalated) {
                throw ValidationException::withMessages(['status' => 'This return is not waiting for the Emall team.']);
            }

            $to = match ($decision) {
                'approve' => ReturnStatus::Approved,
                'uphold_decline' => ReturnStatus::Closed,
                default => throw ValidationException::withMessages(['decision' => 'Choose approve or uphold the decline.']),
            };

            $locked->update(['status' => $to, 'resolved_by' => $operator->id, 'resolution_note' => mb_substr(trim($note), 0, 2000), 'responded_at' => now()]);

            DB::afterCommit(fn () => $this->notifier->returnDecision($locked->load('item', 'order'), $to === ReturnStatus::Approved ? 'return_approved' : 'return_declined'));

            return $locked;
        });
    }

    /**
     * The operator's queue: escalated returns, oldest first.
     *
     * @return Builder<ReturnRequest>
     */
    public function operatorQueue(): Builder
    {
        return ReturnRequest::query()->where('status', ReturnStatus::Escalated->value)
            ->with(['item', 'order:id,user_id,guest_email,total,currency', 'seller:id,name'])->orderBy('escalated_at')->orderBy('id');
    }

    /**
     * A seller's incoming returns, newest first.
     *
     * @return Builder<ReturnRequest>
     */
    public function forSeller(User $seller): Builder
    {
        return ReturnRequest::query()->where('seller_id', $seller->id)->with('item')->latest('id');
    }

    /**
     * @param  array<int, ReturnStatus>  $from
     */
    private function decide(ReturnRequest $return, User $actor, ReturnStatus $to, ?string $note, array $from, string $notice): ReturnRequest
    {
        return DB::transaction(function () use ($return, $actor, $to, $note, $from, $notice) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);

            $this->assertMay($locked, $actor);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'This return can no longer be '.($to === ReturnStatus::Approved ? 'approved' : 'declined').'.']);
            }

            $locked->update([
                'status' => $to,
                'responded_at' => now(),
                'seller_response' => $note !== null ? mb_substr(trim($note), 0, 2000) : null,
                'resolved_by' => $actor->is_platform_operator && $actor->id !== $locked->seller_id ? $actor->id : null,
            ]);

            DB::afterCommit(fn () => $this->notifier->returnDecision($locked->load('item', 'order'), $notice));

            return $locked;
        });
    }

    private function assertMay(ReturnRequest $return, User $actor): void
    {
        if ($actor->id !== $return->seller_id && ! $actor->is_platform_operator) {
            throw new AuthorizationException('This return belongs to another seller.');
        }
    }
}
