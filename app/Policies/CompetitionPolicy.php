<?php

namespace App\Policies;

use App\Models\Competition;
use App\Models\User;

class CompetitionPolicy
{
    /**
     * Determine whether the user can rename the competition.
     */
    public function update(User $user, Competition $competition): bool
    {
        return $user->id === $competition->user_id;
    }

    /**
     * Determine whether the user can delete the competition (the caller checks that it is unused).
     */
    public function delete(User $user, Competition $competition): bool
    {
        return $user->id === $competition->user_id;
    }
}
