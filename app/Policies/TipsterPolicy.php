<?php

namespace App\Policies;

use App\Models\Tipster;
use App\Models\User;

class TipsterPolicy
{
    /**
     * Determine whether the user can rename the tipster.
     */
    public function update(User $user, Tipster $tipster): bool
    {
        return $user->id === $tipster->user_id;
    }

    /**
     * Determine whether the user can delete the tipster (the caller checks that it is unused).
     */
    public function delete(User $user, Tipster $tipster): bool
    {
        return $user->id === $tipster->user_id;
    }
}
