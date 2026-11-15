<?php

namespace App\Notifications\Push;

enum FcmResult
{
    case Sent;

    /** FCM no longer knows the token: the app was uninstalled or its data cleared. */
    case Unregistered;

    case Failed;
}
