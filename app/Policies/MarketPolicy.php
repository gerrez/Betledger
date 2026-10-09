<?php

namespace App\Policies;

use App\Models\Market;
use App\Models\User;

class MarketPolicy
{
    /**
     * Determine whether the user can rename the market.
     */
    public function update(User $user, Market $market): bool
    {
        return $user->id === $market->user_id;
    }

    /**
     * Determine whether the user can delete the market (the caller checks that it is unused).
     */
    public function delete(User $user, Market $market): bool
    {
        return $user->id === $market->user_id;
    }
}
