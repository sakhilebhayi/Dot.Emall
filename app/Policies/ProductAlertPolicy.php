<?php

namespace App\Policies;

use App\Models\ProductAlert;
use App\Models\User;

/** Only the owner reads or changes their own ProductAlert. */
class ProductAlertPolicy
{
    public function view(User $user, ProductAlert $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function update(User $user, ProductAlert $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, ProductAlert $model): bool
    {
        return $user->id === $model->user_id;
    }
}
