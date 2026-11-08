<?php

namespace App\Services\Operator;

use App\Models\OperatorAccessEntry;
use App\Models\OperatorAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The operator audit trail and the POPIA access log. Every moderation decision, money decision and role change is written here
 * by the service that makes it, in the same transaction, so a decision without its entry cannot be saved.
 */
class OperatorAudit
{
    /** @var array<string, string> action key => the sentence the log page prints */
    public const ACTIONS = [
        'listing.approve' => 'Approved a listing',
        'listing.reject' => 'Rejected a listing',
        'listing.takedown' => 'Took a live listing down',
        'listing.flag_dismiss' => 'Judged a flagged listing fine',
        'store.approve' => 'Approved a store',
        'store.reject' => 'Sent a store back',
        'store.suspend' => 'Suspended a store',
        'store.reinstate' => 'Reinstated a store',
        'review.release' => 'Released a review',
        'review.remove' => 'Removed a review',
        'question.release' => 'Released a question',
        'question.remove' => 'Removed a question',
        'answer.remove' => 'Removed an answer',
        'report.dismiss' => 'Dismissed reports',
        'report.action' => 'Acted on reports',
        'return.approve' => 'Settled a return for the buyer',
        'return.approve_refund' => 'Settled a return for the buyer and refunded',
        'return.uphold_decline' => 'Upheld a seller\'s declined return',
        'payment.review' => 'Recorded a payment review',
        'refund.retry' => 'Retried a refund',
        'refund.mark_done' => 'Marked a refund as done by hand',
        'reconcile.run' => 'Ran the ledger reconciliation',
        'collection.create' => 'Created a collection',
        'collection.update' => 'Changed a collection',
        'collection.delete' => 'Deleted a collection',
        'synonym.add' => 'Added a search synonym',
        'synonym.remove' => 'Removed a search synonym',
        'complement.add' => 'Added an "often needed with" rule',
        'complement.remove' => 'Removed an "often needed with" rule',
        'category.create' => 'Created a category',
        'category.update' => 'Changed a category',
        'category.attribute' => 'Changed a category attribute',
        'featured.add' => 'Featured a store',
        'featured.remove' => 'Stopped featuring a store',
        'operator.grant' => 'Made someone an operator',
        'operator.revoke' => 'Removed operator access',
    ];

    /**
     * @param  Model|array{type: string, id: int, label?: string}|null  $subject
     * @param  array<string, mixed>  $meta
     */
    public function record(User $actor, string $action, Model|array|null $subject = null, ?string $reason = null, array $meta = []): OperatorAuditEntry
    {
        [$type, $id, $label] = $this->describe($subject);

        return OperatorAuditEntry::query()->create([
            'actor_id' => $actor->id,
            'actor_name' => mb_substr($actor->name, 0, 190),
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** @return Builder<OperatorAuditEntry> */
    public function query(?string $action = null, ?int $actorId = null, ?string $subjectType = null, ?int $subjectId = null): Builder
    {
        return OperatorAuditEntry::query()
            ->when($action, fn (Builder $q) => str_ends_with($action, '.*') ? $q->where('action', 'like', substr($action, 0, -1).'%') : $q->where('action', $action))
            ->when($actorId, fn (Builder $q) => $q->where('actor_id', $actorId))
            ->when($subjectType, fn (Builder $q) => $q->where('subject_type', $subjectType))
            ->when($subjectId, fn (Builder $q) => $q->where('subject_id', $subjectId))
            ->orderByDesc('id');
    }

    public function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    /** Opening a person's data in the support view. One row per opening. */
    public function recordAccess(User $actor, string $type, int $id, string $label, ?string $purpose = null): OperatorAccessEntry
    {
        return OperatorAccessEntry::query()->create([
            'actor_id' => $actor->id,
            'actor_name' => mb_substr($actor->name, 0, 190),
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_label' => mb_substr($label, 0, 255),
            'purpose' => $purpose !== null ? mb_substr(trim($purpose), 0, 255) : null,
        ]);
    }

    /** @return array{0: ?string, 1: ?int, 2: ?string} */
    private function describe(Model|array|null $subject): array
    {
        if ($subject === null) {
            return [null, null, null];
        }

        if (is_array($subject)) {
            return [$subject['type'], $subject['id'], $subject['label'] ?? null];
        }

        $type = match (true) {
            $subject instanceof \App\Models\Product => 'product',
            $subject instanceof \App\Models\Store => 'store',
            $subject instanceof \App\Models\Review => 'review',
            $subject instanceof \App\Models\ProductQuestion => 'question',
            $subject instanceof \App\Models\ProductAnswer => 'answer',
            $subject instanceof \App\Models\ReturnRequest => 'return',
            $subject instanceof \App\Models\Payment => 'payment',
            $subject instanceof \App\Models\Refund => 'refund',
            $subject instanceof \App\Models\User => 'user',
            $subject instanceof \App\Models\Collection => 'collection',
            $subject instanceof \App\Models\StoreCategory => 'category',
            default => strtolower(class_basename($subject)),
        };

        $label = match (true) {
            $subject instanceof \App\Models\Product, $subject instanceof \App\Models\Store, $subject instanceof \App\Models\StoreCategory => $subject->name,
            $subject instanceof \App\Models\Collection => $subject->title,
            $subject instanceof \App\Models\User => $subject->name,
            $subject instanceof \App\Models\Payment => $subject->reference,
            $subject instanceof \App\Models\ReturnRequest => 'Return #'.$subject->id,
            default => ucfirst($type).' #'.$subject->getKey(),
        };

        return [$type, (int) $subject->getKey(), $label];
    }
}
