<?php

namespace App\Notifications\Push;

use App\Models\User;

/**
 * A notification that can be sent to the user's phones through {@see PushChannel}.
 */
interface PushNotification
{
    public function toPush(User $notifiable): PushMessage;
}
