<?php

namespace App\Actions\Jetstream;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    /**
     * @throws ValidationException while the account still has orders or refunds that someone is owed
     */
    public function delete(User $user): void
    {
        $this->refuseWhileOrdersAreOpen($user);

        DB::transaction(function () use ($user) {
            // Archived through the model so the listing photos are removed from the disk too: the database cascade
            // that follows the account would drop the rows and leave the files behind.
            Product::query()->whereIn('store_id', $user->stores()->select('id'))->get()->each->delete();

            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }

    /**
     * Deleting a user cascades to their orders, and a seller's lines lose their seller. An account with a paid order
     * still on its way, a line a buyer is waiting for, or a refund not yet paid back would take that record (and the
     * stock and money behind it) with it. The person finishes or cancels those first.
     */
    private function refuseWhileOrdersAreOpen(User $user): void
    {
        if (($reason = $this->blockedReason($user)) !== null) {
            throw ValidationException::withMessages(['password' => $reason]);
        }
    }

    /**
     * Why this account cannot be deleted right now, in words for the person; null when it can. The account page reads the same answer, so
     * what it says and what the delete button does cannot disagree.
     */
    public function blockedReason(User $user): ?string
    {
        $open = [OrderStatus::Pending->value, OrderStatus::Processing->value, OrderStatus::Shipped->value];
        $owedBack = [RefundStatus::Pending->value, RefundStatus::Submitted->value, RefundStatus::Failed->value];

        $asBuyer = Order::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('items', fn ($lines) => $lines->whereIn('status', $open))
            ->exists()
            || Refund::query()->whereIn('status', $owedBack)->whereIn('order_id', Order::query()->where('user_id', $user->id)->select('id'))->exists();

        $asSeller = OrderItem::query()
            ->where('seller_id', $user->id)
            ->whereIn('status', $open)
            ->whereHas('order', fn ($order) => $order->fulfillable())
            ->exists();

        if ($asBuyer || $asSeller) {
            return $asSeller
                ? 'You still have paid orders to ship or cancel. Finish them first, then delete your account.'
                : 'You still have orders on their way or a refund being paid back. Wait for them to finish, or cancel them, then delete your account.';
        }

        return null;
    }
}
