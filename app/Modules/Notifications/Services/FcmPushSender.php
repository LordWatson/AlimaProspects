<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Data\PushResult;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends push notifications over **Firebase Cloud Messaging HTTP v1** (Phase 12).
 *
 * ### Why v1 and a service account, not `FCM_SERVER_KEY`
 *
 * The legacy FCM endpoint (`/fcm/send`, authenticated with a server key) was **retired by Google in
 * June 2024** and now returns 404 for everyone. `FCM_SERVER_KEY` in `.env.example` is a leftover
 * from before the mobile app was planned and is no longer a usable credential. HTTP v1 authenticates
 * with an OAuth2 access token minted from a **service-account key**, so that is what this reads
 * (`FCM_PROJECT_ID` + `FCM_CREDENTIALS`, a path to the downloaded JSON).
 *
 * The JWT is signed here with `openssl_sign` rather than by pulling in a JWT library: it is one
 * RS256 signature over two base64 segments, and a dependency for that is a dependency to keep
 * patched for ever.
 *
 * ### Behaviour that matters
 *
 * - **One request per token.** HTTP v1 has no multicast (the batch endpoint is deprecated), and a
 *   member has one to three devices — so a loop is the right shape and a failing device cannot take
 *   the others with it.
 * - **A permanently rejected token is reported, not retried.** `UNREGISTERED` and
 *   `INVALID_ARGUMENT` mean the app was uninstalled, the device wiped, or the token belongs to
 *   another Firebase project. The caller deletes those rows; anything else is transient.
 * - **Never throws.** Push is the least important channel — a member who misses one still has the
 *   bell and, often, the email. A provider outage must not fail the notification or retry the
 *   queued job, so failures are counted, logged and returned.
 * - **Nothing is logged that could reach the device.** No tokens, no titles, no bodies — counts and
 *   status codes only (CLAUDE.md logging rules).
 */
final class FcmPushSender implements PushSender
{
    private const string BREAKER_KEY = 'fcm';

    private const string TOKEN_CACHE_KEY = 'notifications:fcm:access_token';

    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function send(array $tokens, PushMessage $message): PushResult
    {
        if ($tokens === []) {
            return PushResult::none();
        }

        $credentials = $this->credentials();

        if ($credentials === null) {
            // Not configured. Not an error: a deployment without a mobile app has no reason to
            // hold Firebase credentials, and the preference screen says push needs a device.
            return PushResult::none();
        }

        if (! $this->breaker->allows(self::BREAKER_KEY)) {
            Log::channel('notifications')->warning('Push skipped: the FCM circuit is open.', [
                'tokens' => count($tokens),
            ]);

            return PushResult::none();
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            $this->breaker->recordFailure(self::BREAKER_KEY);

            return PushResult::none();
        }

        $sent = 0;
        $failed = 0;
        $invalid = [];
        $anySucceeded = false;

        foreach (array_unique($tokens) as $token) {
            $outcome = $this->sendOne($credentials['project_id'], $accessToken, $token, $message);

            if ($outcome === 'sent') {
                $sent++;
                $anySucceeded = true;

                continue;
            }

            $failed++;

            if ($outcome === 'invalid') {
                $invalid[] = $token;
            }
        }

        // One success is enough to say the provider is up: a single dead token must not trip the
        // breaker for everybody.
        $anySucceeded
            ? $this->breaker->recordSuccess(self::BREAKER_KEY)
            : $this->breaker->recordFailure(self::BREAKER_KEY);

        Log::channel('notifications')->info('Push sent.', [
            'sent' => $sent,
            'failed' => $failed,
            'invalid' => count($invalid),
        ]);

        return new PushResult(sent: $sent, failed: $failed, invalidTokens: $invalid);
    }

    /**
     * @return 'sent'|'invalid'|'failed'
     */
    private function sendOne(string $projectId, string $accessToken, string $token, PushMessage $message): string
    {
        try {
            $response = $this->http
                ->withToken($accessToken)
                ->timeout((int) config('notifications.push.timeout', 10))
                ->connectTimeout((int) config('notifications.push.connect_timeout', 5))
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $message->title,
                            'body' => $message->body,
                        ],
                        // Every value must be a string in v1, or the API rejects the whole message.
                        'data' => array_map(static fn (string $value): string => $value, $message->data),
                    ],
                ]);
        } catch (Throwable $e) {
            Log::channel('notifications')->warning('Push request failed.', ['exception' => $e::class]);

            return 'failed';
        }

        if ($response->successful()) {
            return 'sent';
        }

        return $this->isPermanentlyRejected($response) ? 'invalid' : 'failed';
    }

    /**
     * Whether the provider will never accept this token again.
     *
     * 404 is `UNREGISTERED` — the app is gone from that device. A 400 `INVALID_ARGUMENT` naming the
     * token is a token from another project, or a corrupted one; a 400 about anything else is our
     * payload's fault and deleting the device over it would be wrong.
     */
    private function isPermanentlyRejected(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        if ($response->status() !== 400) {
            return false;
        }

        $error = (string) $response->json('error.status', '');
        $message = (string) $response->json('error.message', '');

        return $error === 'INVALID_ARGUMENT' && str_contains(strtolower($message), 'token');
    }

    /**
     * A cached OAuth2 access token for the service account.
     *
     * Cached just under its hour-long life, because minting one is a signature plus a round trip
     * and a notification burst would otherwise do both per device.
     *
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->signedAssertion($credentials);

        if ($assertion === null) {
            return null;
        }

        try {
            $response = $this->http
                ->timeout((int) config('notifications.push.timeout', 10))
                ->asForm()
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (Throwable $e) {
            Log::channel('notifications')->error('Could not mint an FCM access token.', [
                'exception' => $e::class,
            ]);

            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            Log::channel('notifications')->error('FCM refused the service-account assertion.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        // Google issues these for an hour; 55 minutes leaves room for a slow send.
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes(55));

        return $token;
    }

    /**
     * The RS256 JWT that exchanges for an access token.
     *
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function signedAssertion(array $credentials): ?string
    {
        $now = time();

        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            Log::channel('notifications')->error('The FCM service-account private key could not be read.');

            return null;
        }

        if (! openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::channel('notifications')->error('Could not sign the FCM service-account assertion.');

            return null;
        }

        return $header.'.'.$claims.'.'.$this->base64Url($signature);
    }

    /**
     * The service-account fields, or null when push isn't configured for this deployment.
     *
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $path = config('notifications.push.credentials');

        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return null;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode((string) file_get_contents($path), true);

        if (! is_array($json)) {
            Log::channel('notifications')->error('The FCM service-account file is not valid JSON.');

            return null;
        }

        // The project id may be overridden in config, since a deployment can point one key at a
        // different project than the file was downloaded for.
        $projectId = (string) (config('notifications.push.project_id') ?: ($json['project_id'] ?? ''));
        $clientEmail = (string) ($json['client_email'] ?? '');
        $privateKey = (string) ($json['private_key'] ?? '');

        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            Log::channel('notifications')->error('The FCM service-account file is missing required fields.');

            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
