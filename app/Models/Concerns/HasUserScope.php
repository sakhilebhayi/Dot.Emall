<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Emall is a two-sided marketplace: most models (Store, Product,
 * StoreCategory, ProductImage, Review, Order) are legitimately read
 * across users -- a buyer views another user's store/product/reviews,
 * and a seller's dashboard reads Orders placed by other users that
 * merely contain the seller's own products (see OrderPolicy's docblock).
 * Blindly scoping those to Auth::id() would silently break real,
 * intended cross-user reads rather than fix a leak.
 *
 * CartItem is the one model here with no such cross-user read path --
 * a shopping cart is never shown to, or queried by, anyone but its
 * owner. This trait is the direct analog of Dot.Finance/Dot.Mines'
 * scope traits, applied only where it's actually safe: it makes a
 * query against the model scoped to the authenticated user by default,
 * the same way Dot.Mines' HasTeamFilters scopes every tenant-owned
 * model -- the goal is that a forgotten where('user_id', ...) call in
 * a future controller can no longer leak another user's rows, because
 * the model itself never returns unscoped results while a user is
 * authenticated.
 *
 * mass-assignment still sets user_id explicitly at create time (see
 * CartController::store()); this scope only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.'.static::userScopeColumn(), Auth::id());
            }
        });
    }

    /**
     * The column that stores the owning user's id. Override in models
     * where it isn't literally "user_id" (e.g. Store uses "owner_id",
     * though Store itself does not use this trait -- see class docblock).
     */
    protected static function userScopeColumn(): string
    {
        return 'user_id';
    }
}
