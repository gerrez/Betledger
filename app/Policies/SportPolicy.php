<?php

namespace App\Policies;

use App\Models\Sport;
use App\Models\User;

class SportPolicy
{
    /**
     * Determine whether the user can rename the sport.
     */
    public function update(User $user, Sport $sport): bool
    {
        return $user->id === $sport->user_id;
    }

    /**
     * Determine whether the user can delete the sport (the caller checks that it is unused).
     */
    public function delete(User $user, Sport $sport): bool
    {
        return $user->id === $sport->user_id;
    }
}
