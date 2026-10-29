<?php

namespace App\Notifications\Push;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sends a notification to every phone the user registered, and forgets the devices
 * that Firebase reports as unregistered.
 */
class PushChannel
{
    public function __construct(private FcmClient $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof PushNotification) {
            throw new InvalidArgumentException('Push notifications go to users and must implement '.PushNotification::class.'.');
        }

        if (! $this->fcm->isConfigured()) {
            Log::warning('Push notification not sent: FCM_CREDENTIALS is not set.', ['notification' => $notification::class]);

            return;
        }

        $message = $notification->toPush($notifiable);

        foreach ($notifiable->pushDevices()->get() as $device) {
            if ($this->fcm->send($message->toFcm($device->token)) === FcmResult::Unregistered) {
                $device->delete();
            }
        }
    }
}
