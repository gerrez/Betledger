<?php

namespace App\Policies;

use App\Models\PushDevice;
use App\Models\User;

class PushDevicePolicy
{
    /**
     * Determine whether the user can remove the device from their account.
     */
    public function delete(User $user, PushDevice $pushDevice): bool
    {
        return $user->id === $pushDevice->user_id;
    }
}
