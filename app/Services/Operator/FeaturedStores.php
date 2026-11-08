<?php

namespace App\Services\Operator;

use App\Models\FeaturedStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The operator's editorial pick of stores. It is labelled as the editors' selection wherever it is shown and is never for sale or a
 * ranking input. Only open stores are returned to shoppers; a suspended store drops out without being removed from the list.
 */
class FeaturedStores
{
    public const LIMIT = 12;

    public function __construct(private readonly OperatorAudit $audit) {}

    /** @return Collection<int, FeaturedStore> everything in the list, for the operator */
    public function all(): Collection
    {
        return FeaturedStore::query()->with('store:id,name,slug,status,is_active,logo_path')->orderBy('position')->orderBy('id')->get();
    }

    /** @return list<int> the ids of featured stores a shopper may see, in order: the call for the homepage and the stores page */
    public function ids(): array
    {
        return FeaturedStore::query()->join('stores', 'stores.id', '=', 'featured_stores.store_id')->where('stores.is_active', true)
            ->orderBy('featured_stores.position')->orderBy('featured_stores.id')->pluck('stores.id')->map(fn ($i) => (int) $i)->all();
    }

    public function add(Store $store, User $operator, ?string $note = null): FeaturedStore
    {
        if (! $store->is_active) {
            throw ValidationException::withMessages(['store_id' => 'Only an open store can be featured.']);
        }

        if (FeaturedStore::query()->count() >= self::LIMIT && ! FeaturedStore::query()->where('store_id', $store->id)->exists()) {
            throw ValidationException::withMessages(['store_id' => 'Up to '.self::LIMIT.' stores can be featured. Remove one first.']);
        }

        return DB::transaction(function () use ($store, $operator, $note) {
            $row = FeaturedStore::query()->firstOrCreate(['store_id' => $store->id], ['position' => (int) FeaturedStore::query()->max('position') + 1, 'note' => $note ? mb_substr(trim($note), 0, 190) : null, 'added_by' => $operator->id]);
            $this->audit->record($operator, 'featured.add', $store, $note);

            return $row;
        });
    }

    public function remove(Store $store, User $operator): void
    {
        DB::transaction(function () use ($store, $operator) {
            FeaturedStore::query()->where('store_id', $store->id)->delete();
            $this->audit->record($operator, 'featured.remove', $store);
        });
    }
}
