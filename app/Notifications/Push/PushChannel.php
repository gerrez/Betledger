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

    public function send(object $notifiable, Notification $notification): PushReport
    {
        if (! $notifiable instanceof User || ! $notification instanceof PushNotification) {
            throw new InvalidArgumentException('Push notifications go to users and must implement '.PushNotification::class.'.');
        }

        if (! $this->fcm->isConfigured()) {
            $reason = $this->fcm->configurationError ?? 'FCM_CREDENTIALS is not set.';
            Log::warning("Push notification not sent: {$reason}", ['notification' => $notification::class]);

            return new PushReport(errors: [$reason]);
        }

        $message = $notification->toPush($notifiable);
        $sent = $removed = $failed = 0;
        $errors = [];

        foreach ($notifiable->pushDevices()->get() as $device) {
            $result = $this->fcm->send($message->toFcm($device->token));

            if ($result->status === FcmResult::Sent) {
                $sent++;
            } elseif ($result->status === FcmResult::Unregistered) {
                $device->delete();
                $removed++;
            } else {
                $failed++;
                $errors[] = $result->error ?? 'Unknown error.';
            }
        }

        return new PushReport($sent, $removed, $failed, array_values(array_unique($errors)));
    }
}
