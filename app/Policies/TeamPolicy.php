<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can rename the team.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->id === $team->user_id;
    }

    /**
     * Determine whether the user can delete the team (the caller checks that it is unused).
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->id === $team->user_id;
    }
}
