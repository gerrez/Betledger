<?php

namespace App\Notifications\Push;

/**
 * What a push notification says, independent of the device it goes to.
 *
 * A "notification" message is shown by Android itself while the app is in the
 * background; a data-only message is always handed to the app, which builds the
 * notification. Both carry the title, body and URL as data, so the app can open the
 * URL when the notification is tapped.
 */
readonly class PushMessage
{
    /**
     * The Android notification channel the app creates on start (see docs/spec.md).
     */
    public const DEFAULT_CHANNEL = 'general';

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public bool $dataOnly = false,
        public string $channel = self::DEFAULT_CHANNEL,
    ) {}

    /**
     * The message for the FCM HTTP v1 API, addressed to one device.
     *
     * @return array<string, mixed>
     */
    public function toFcm(string $token): array
    {
        $message = [
            'token' => $token,
            'data' => [
                'title' => $this->title,
                'body' => $this->body,
                'url' => $this->url ?? '',
                'channel' => $this->channel,
            ],
            'android' => [
                'priority' => 'high',
            ],
        ];

        if (! $this->dataOnly) {
            $message['notification'] = [
                'title' => $this->title,
                'body' => $this->body,
            ];
            $message['android']['notification'] = [
                'channel_id' => $this->channel,
            ];
        }

        return $message;
    }
}
