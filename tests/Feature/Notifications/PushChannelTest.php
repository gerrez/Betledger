<?php

namespace Tests\Feature\Notifications;

use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\Push\FcmClient;
use App\Notifications\Push\PushChannel;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PushChannelTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/betledger-test/messages:send';

    private string $publicKey;

    /**
     * Use a freshly generated service account key, so the test can check the signature.
     */
    private function configureFcm(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->publicKey = $details['key'];

        $this->app->instance(FcmClient::class, FcmClient::fromConfig(json_encode([
            'type' => 'service_account',
            'project_id' => 'betledger-test',
            'client_email' => 'push@betledger-test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => self::TOKEN_URI,
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @param  array<string, mixed>  $sendResponse
     */
    private function fakeGoogle(array $sendResponse = ['name' => 'projects/betledger-test/messages/1'], int $sendStatus = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'access-123', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            self::SEND_URL => Http::response($sendResponse, $sendStatus),
        ]);
    }

    public function test_a_notification_is_sent_to_every_device_of_the_user(): void
    {
        $this->configureFcm();
        $this->fakeGoogle();
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create(['token' => 'phone-1']);
        PushDevice::factory()->for($user)->create(['token' => 'phone-2']);
        PushDevice::factory()->create(['token' => 'someone-else']);

        $user->notify(new TestPushNotification);

        $sent = Http::recorded(fn (Request $request): bool => $request->url() === self::SEND_URL);
        $this->assertSame(['phone-1', 'phone-2'], $sent->map(fn (array $pair): mixed => $pair[0]['message']['token'])->sort()->values()->all());

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEND_URL
            && $request->hasHeader('Authorization', 'Bearer access-123')
            && $request['message']['notification'] === [
                'title' => 'BetLedger test',
                'body' => 'Notification message: shown by Android while the app is in the background.',
            ]
            && $request['message']['android']['notification'] === ['channel_id' => 'general']
            && $request['message']['data'] === [
                'title' => 'BetLedger test',
                'body' => 'Notification message: shown by Android while the app is in the background.',
                'url' => route('settings.notifications'),
                'channel' => 'general',
            ]);
    }

    public function test_a_data_message_has_no_notification_for_android_to_show(): void
    {
        $this->configureFcm();
        $this->fakeGoogle();
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();

        $user->notify(new TestPushNotification(dataOnly: true));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEND_URL
            && ! array_key_exists('notification', $request['message'])
            && ! array_key_exists('notification', $request['message']['android'])
            && $request['message']['data']['body'] === 'Data message: the app built this notification itself.');
    }

    public function test_the_access_token_is_requested_with_a_jwt_signed_by_the_service_account(): void
    {
        $this->configureFcm();
        $this->fakeGoogle();
        $this->freezeSecond();
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->count(2)->create();

        $user->notify(new TestPushNotification);

        $tokenRequests = Http::recorded(fn (Request $request): bool => $request->url() === self::TOKEN_URI);
        $this->assertCount(1, $tokenRequests, 'The access token is cached between messages.');

        $request = $tokenRequests->first()[0];
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

        [$header, $claims, $signature] = explode('.', $request['assertion']);
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->base64UrlDecode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($this->base64UrlDecode($header), true));
        $this->assertSame([
            'iss' => 'push@betledger-test.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::TOKEN_URI,
            'iat' => now()->getTimestamp(),
            'exp' => now()->getTimestamp() + 3600,
        ], json_decode($this->base64UrlDecode($claims), true));
    }

    public function test_devices_that_firebase_reports_as_unregistered_are_removed(): void
    {
        $this->configureFcm();
        $this->fakeGoogle([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found.',
                'status' => 'NOT_FOUND',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ],
        ], 404);
        $user = User::factory()->create();
        $device = PushDevice::factory()->for($user)->create();

        $user->notify(new TestPushNotification);

        $this->assertModelMissing($device);
    }

    public function test_devices_are_kept_when_sending_fails_for_another_reason(): void
    {
        Log::spy();
        $this->configureFcm();
        $this->fakeGoogle(['error' => ['code' => 404, 'message' => 'Project not found.', 'status' => 'NOT_FOUND']], 404);
        $user = User::factory()->create();
        $first = PushDevice::factory()->for($user)->create();
        $second = PushDevice::factory()->for($user)->create();

        $user->notify(new TestPushNotification);

        Http::assertSentCount(3);
        $this->assertModelExists($first);
        $this->assertModelExists($second);
        Log::shouldHaveReceived('warning')->with('FCM send failed. Firebase answered 404: Project not found.')->twice();
    }

    public function test_nothing_is_sent_without_credentials(): void
    {
        Http::preventStrayRequests();
        Log::spy();
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();

        $user->notify(new TestPushNotification);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('Push notification not sent: FCM_CREDENTIALS is not set.', ['notification' => TestPushNotification::class])->once();
    }

    public function test_the_credentials_can_be_a_path_to_the_key_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        $this->assertIsString($path);
        file_put_contents($path, json_encode(['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'k'], JSON_THROW_ON_ERROR));

        try {
            $this->assertTrue(FcmClient::fromConfig($path)->isConfigured());
        } finally {
            unlink($path);
        }

        $this->assertFalse(FcmClient::fromConfig(null)->isConfigured());
        $this->assertFalse(FcmClient::fromConfig('')->isConfigured());
    }

    public function test_unreadable_or_incomplete_credentials_leave_push_off_with_the_reason(): void
    {
        $this->assertRefused('/does/not/exist.json', "FCM_CREDENTIALS: can't read the file /does/not/exist.json.");
        $this->assertRefused('{not json', 'FCM_CREDENTIALS is not a valid service account JSON key.');
        $this->assertRefused('{"project_id": "p", "client_email": "e"}', 'FCM_CREDENTIALS: the service account key has no private_key.');
    }

    public function test_a_refused_service_account_key_is_reported_instead_of_thrown(): void
    {
        Log::spy();
        $this->configureFcm();
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URI => Http::response(['error' => 'invalid_grant'], 400)]);
        $user = User::factory()->create();
        $device = PushDevice::factory()->for($user)->create();

        $report = $this->app->make(PushChannel::class)->send($user, new TestPushNotification);

        $this->assertSame([0, 0, 1], [$report->sent, $report->removed, $report->failed]);
        $this->assertStringStartsWith('Google refused the service account key: ', $report->errors[0]);
        $this->assertModelExists($device);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::SEND_URL);
    }

    public function test_a_private_key_that_cannot_sign_is_reported_instead_of_thrown(): void
    {
        Log::spy();
        Http::preventStrayRequests();
        $this->app->instance(FcmClient::class, new FcmClient(['project_id' => 'betledger-test', 'client_email' => 'e', 'private_key' => 'not a key']));
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create();

        $report = $this->app->make(PushChannel::class)->send($user, new TestPushNotification);

        $this->assertSame(1, $report->failed);
        Http::assertNothingSent();
    }

    public function test_the_report_counts_sent_and_removed_devices(): void
    {
        $this->configureFcm();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'access-123']),
            self::SEND_URL => fn (Request $request) => $request['message']['token'] === 'gone'
                ? Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/betledger-test/messages/1']),
        ]);
        $user = User::factory()->create();
        PushDevice::factory()->for($user)->create(['token' => 'here']);
        PushDevice::factory()->for($user)->create(['token' => 'gone']);

        $report = $this->app->make(PushChannel::class)->send($user, new TestPushNotification);

        $this->assertSame([1, 1, 0, []], [$report->sent, $report->removed, $report->failed, $report->errors]);
        $this->assertSame(['here'], $user->pushDevices()->pluck('token')->all());
    }

    private function assertRefused(string $credentials, string $message): void
    {
        $client = FcmClient::fromConfig($credentials);

        $this->assertFalse($client->isConfigured());
        $this->assertSame($message, $client->configurationError);
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
