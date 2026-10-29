<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Push\PushChannel;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushNotification;
use Illuminate\Notifications\Notification;

/**
 * The "Send test" buttons on Settings → Notifications. Sent right away (not queued) so
 * the phone buzzes while the user is looking at it.
 */
class TestPushNotification extends Notification implements PushNotification
{
    public function __construct(public bool $dataOnly = false) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PushChannel::class];
    }

    public function toPush(User $notifiable): PushMessage
    {
        return new PushMessage(
            title: 'BetLedger test',
            body: $this->dataOnly
                ? 'Data message: the app built this notification itself.'
                : 'Notification message: shown by Android while the app is in the background.',
            url: route('settings.notifications'),
            dataOnly: $this->dataOnly,
        );
    }
}
