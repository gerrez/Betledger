<?php

namespace App\Policies;

use App\Models\Bookmaker;
use App\Models\User;

class BookmakerPolicy
{
    /**
     * Determine whether the user can edit, deactivate or reactivate the bookmaker.
     */
    public function update(User $user, Bookmaker $bookmaker): bool
    {
        return $user->id === $bookmaker->user_id;
    }
}
