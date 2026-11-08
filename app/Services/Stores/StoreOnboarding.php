<?php

namespace App\Services\Stores;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\Addresses\AddressRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A seller's store from first draft to live, with an operator in the loop and a reason on every refusal.
 *
 *   draft -> pending_review (the owner submits, once the basics are filled in)
 *   pending_review -> active (an operator approves) | draft (an operator sends it back WITH a reason)
 *   active -> suspended (an operator, WITH a reason) -> active (reinstated)
 *
 * stores.is_active remains the live switch the storefront queries read: a store is open exactly when it is active AND not closed
 * by its owner; suspended, draft and pending stores are closed. By default (config emall.seller.requires_review = false) a
 * new store starts live, as it always did; turn the setting on and it starts as a draft.
 * Registration and VAT numbers are claims the seller makes: stored as typed, labelled claimed everywhere, never verified here.
 */
class StoreOnboarding
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Store $store = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'region' => ['nullable', 'string', Rule::in(AddressRules::PROVINCES)],
            'city' => ['nullable', 'string', 'max:100'],
            'return_policy' => ['nullable', 'string', 'max:4000'],
            'shipping_policy' => ['nullable', 'string', 'max:4000'],
            'vat_registered' => ['nullable', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z\- ]{4,40}$/'],
            'registration_number' => ['nullable', 'string', 'max:60', 'regex:/^[0-9A-Za-z\/\- ]{4,60}$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(User $owner, array $data): Store
    {
        $clean = Validator::make($data, $this->rules())->validate();

        $review = (bool) config('emall.seller.requires_review');

        return Store::create(array_merge($this->fields($clean), [
            'owner_id' => $owner->id,
            'slug' => Str::slug($clean['name']).'-'.Str::random(6),
            'status' => $review ? StoreStatus::Draft : StoreStatus::Active,
            'is_active' => ! $review,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Store $store, array $data): Store
    {
        $clean = Validator::make($data, $this->rules($store))->validate();

        $store->update($this->fields($clean));

        return $store->refresh();
    }

    /**
     * What a store still has to fill in before it can be submitted for review.
     *
     * @return array<string, string> field => what is missing
     */
    public function missingForSubmission(Store $store): array
    {
        $missing = [];

        foreach (['contact_email' => 'a contact e-mail address', 'region' => 'the province you ship from', 'return_policy' => 'your return policy', 'shipping_policy' => 'your shipping policy'] as $field => $label) {
            if (blank($store->{$field})) {
                $missing[$field] = "Add {$label}.";
            }
        }

        if ($store->shippingMethods()->where('is_active', true)->doesntExist()) {
            $missing['shipping_methods'] = 'Add at least one shipping method.';
        }

        return $missing;
    }

    public function submit(Store $store): Store
    {
        if (! in_array($store->status, [StoreStatus::Draft], true)) {
            throw ValidationException::withMessages(['status' => 'Only a draft store can be submitted.']);
        }

        if ($missing = $this->missingForSubmission($store)) {
            throw ValidationException::withMessages($missing);
        }

        $store->forceFill(['status' => StoreStatus::PendingReview, 'submitted_at' => now(), 'status_reason' => null])->save();

        return $store->refresh();
    }

    public function approve(Store $store, User $operator): Store
    {
        $this->assertOperator($operator);

        if ($store->status !== StoreStatus::PendingReview) {
            throw ValidationException::withMessages(['status' => 'Only a store waiting for review can be approved.']);
        }

        $store->forceFill(['status' => StoreStatus::Active, 'is_active' => true, 'status_reason' => null, 'reviewed_at' => now(), 'reviewed_by' => $operator->id])->save();

        return $store->refresh();
    }

    /**
     * Sends a store back to its owner with the reason, to fix and submit again.
     */
    public function reject(Store $store, User $operator, string $reason): Store
    {
        $this->assertOperator($operator);
        $this->assertReason($reason);

        if ($store->status !== StoreStatus::PendingReview) {
            throw ValidationException::withMessages(['status' => 'Only a store waiting for review can be sent back.']);
        }

        $store->forceFill(['status' => StoreStatus::Draft, 'is_active' => false, 'status_reason' => trim($reason), 'reviewed_at' => now(), 'reviewed_by' => $operator->id])->save();

        return $store->refresh();
    }

    public function suspend(Store $store, User $operator, string $reason): Store
    {
        $this->assertOperator($operator);
        $this->assertReason($reason);

        $store->forceFill(['status' => StoreStatus::Suspended, 'is_active' => false, 'status_reason' => trim($reason), 'reviewed_at' => now(), 'reviewed_by' => $operator->id])->save();

        return $store->refresh();
    }

    public function reinstate(Store $store, User $operator): Store
    {
        $this->assertOperator($operator);

        if ($store->status !== StoreStatus::Suspended) {
            throw ValidationException::withMessages(['status' => 'Only a suspended store can be reinstated.']);
        }

        $store->forceFill(['status' => StoreStatus::Active, 'is_active' => true, 'status_reason' => null, 'reviewed_at' => now(), 'reviewed_by' => $operator->id])->save();

        return $store->refresh();
    }

    /**
     * Links the store to one of its owner's InfoDot companies (a team that carries an infodot_team_uuid).
     *
     * @throws AuthorizationException
     */
    public function linkTeam(Store $store, Team $team): Store
    {
        $owner = $store->owner;

        if ($owner === null || ! $owner->belongsToTeam($team) || blank($team->infodot_team_uuid)) {
            throw new AuthorizationException('That company is not one of yours in InfoDot.');
        }

        $store->update(['team_id' => $team->id]);

        return $store->refresh();
    }

    /** @return Illuminate\Support\Collection<int, Store> */
    public function reviewQueue()
    {
        return Store::query()->where('status', StoreStatus::PendingReview->value)->orderBy('submitted_at')->with('owner:id,name,email')->get();
    }

    /**
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function fields(array $clean): array
    {
        $clean = array_intersect_key($clean, $this->rules());

        if (array_key_exists('vat_registered', $clean)) {
            $clean['vat_registered'] = (bool) $clean['vat_registered'];
        }

        return $clean;
    }

    private function assertOperator(User $user): void
    {
        if (! $user->is_platform_operator) {
            throw new AuthorizationException('Only the Emall team can do this.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Write the reason, so the seller can act on it.']);
        }
    }
}
