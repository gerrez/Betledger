<?php

namespace App\Notifications\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sends messages with the Firebase Cloud Messaging HTTP v1 API, authenticated as a
 * Firebase service account (OAuth 2 JWT bearer flow, signed with openssl).
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null  $credentials
     */
    public function __construct(private ?array $credentials) {}

    /**
     * Read the service account key from `FCM_CREDENTIALS`: the path to the JSON file
     * downloaded from Firebase, or the JSON itself.
     */
    public static function fromConfig(?string $credentials): self
    {
        if ($credentials === null || trim($credentials) === '') {
            return new self(null);
        }

        $json = str_starts_with(ltrim($credentials), '{') ? $credentials : @file_get_contents($credentials);

        if ($json === false) {
            throw new InvalidArgumentException("FCM_CREDENTIALS: can't read the file {$credentials}.");
        }

        $key = json_decode($json, true);

        if (! is_array($key)) {
            throw new InvalidArgumentException('FCM_CREDENTIALS is not a valid service account JSON key.');
        }

        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (! isset($key[$field]) || ! is_string($key[$field]) || $key[$field] === '') {
                throw new InvalidArgumentException("FCM_CREDENTIALS: the service account key has no {$field}.");
            }
        }

        return new self([
            'project_id' => $key['project_id'],
            'client_email' => $key['client_email'],
            'private_key' => $key['private_key'],
            'token_uri' => is_string($key['token_uri'] ?? null) ? $key['token_uri'] : self::DEFAULT_TOKEN_URI,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->credentials !== null;
    }

    /**
     * Send one message (see {@see PushMessage::toFcm()}). Failures are logged rather than
     * thrown, so one bad device doesn't stop the others.
     *
     * @param  array<string, mixed>  $message
     */
    public function send(array $message): FcmResult
    {
        $credentials = $this->credentials();

        try {
            $response = Http::withToken($this->accessToken())
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('FCM send failed: '.$exception->getMessage());

            return FcmResult::Failed;
        }

        if ($response->successful()) {
            return FcmResult::Sent;
        }

        // Only FCM's own error code, not the HTTP status: a wrong project ID is a 404 too.
        $details = $response->json('error.details');
        $errorCodes = is_array($details) ? array_column($details, 'errorCode') : [];

        if (in_array('UNREGISTERED', $errorCodes, true)) {
            return FcmResult::Unregistered;
        }

        Log::warning('FCM send failed.', ['status' => $response->status(), 'error' => $response->json('error.message')]);

        return FcmResult::Failed;
    }

    /**
     * An OAuth access token for the service account, cached for most of its hour.
     */
    private function accessToken(): string
    {
        $credentials = $this->credentials();

        return Cache::remember('fcm-access-token:'.$credentials['client_email'], now()->addMinutes(50), function () use ($credentials): string {
            $tokenUri = $credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;
            $issuedAt = now()->getTimestamp();

            $token = Http::asForm()
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->signedJwt($credentials['private_key'], [
                        'iss' => $credentials['client_email'],
                        'scope' => self::SCOPE,
                        'aud' => $tokenUri,
                        'iat' => $issuedAt,
                        'exp' => $issuedAt + 3600,
                    ]),
                ])
                ->throw()
                ->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Google returned no FCM access token.');
            }

            return $token;
        });
    }

    /**
     * @param  array<string, string|int>  $claims
     */
    private function signedJwt(string $privateKey, array $claims): string
    {
        $unsigned = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM_CREDENTIALS: the private key could not sign the request.');
        }

        return $unsigned.'.'.self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}
     */
    private function credentials(): array
    {
        return $this->credentials ?? throw new RuntimeException('FCM_CREDENTIALS is not set.');
    }
}
