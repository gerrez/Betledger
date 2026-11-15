<?php

namespace App\Listeners;

use App\Http\Controllers\PushDeviceController;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Session;

/**
 * Logging out in the Android app stops that phone's notifications: the device the
 * session registered is removed from the account.
 */
class ForgetPushDeviceOnLogout
{
    public function handle(Logout $event): void
    {
        $token = Session::pull(PushDeviceController::SESSION_KEY);

        if (is_string($token) && $event->user instanceof User) {
            $event->user->pushDevices()->where('token', $token)->delete();
        }
    }
}
