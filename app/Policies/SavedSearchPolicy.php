<?php

namespace App\Policies;

use App\Models\SavedSearch;
use App\Models\User;

/** Only the owner reads or changes their own SavedSearch. */
class SavedSearchPolicy
{
    public function view(User $user, SavedSearch $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function update(User $user, SavedSearch $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, SavedSearch $model): bool
    {
        return $user->id === $model->user_id;
    }
}
