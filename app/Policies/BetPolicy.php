<?php

namespace App\Policies;

use App\Models\Bet;
use App\Models\User;

/**
 * Selections and tags are managed through their bet, so this covers them too.
 */
class BetPolicy
{
    /**
     * Determine whether the user can see the bet.
     */
    public function view(User $user, Bet $bet): bool
    {
        return $user->id === $bet->user_id;
    }

    /**
     * Determine whether the user can edit or settle the bet.
     */
    public function update(User $user, Bet $bet): bool
    {
        return $user->id === $bet->user_id;
    }

    /**
     * Determine whether the user can delete the bet.
     */
    public function delete(User $user, Bet $bet): bool
    {
        return $user->id === $bet->user_id;
    }
}
