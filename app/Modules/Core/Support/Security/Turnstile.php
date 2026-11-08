<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile verification for the handful of public forms that need one (PL5).
 *
 * In Core beside {@see SafeUrl} rather than in the module that first wanted it, because the
 * unauthenticated surface is about to have more than one entrance: PL5's data-subject request form
 * and PL10's free sample report both face the open internet with no account behind them, and a
 * second copy of this would be a second place the secret is read and a second place it is read
 * wrongly.
 *
 * **It is off until a key exists, and off means pass.** That looks like the wrong default on a
 * security check and is the right one here: the rate limiters are separate and always on, the
 * endpoint it protects writes a row that does nothing until the person clicks a link in their own
 * inbox, and the alternative — failing closed with no key configured — would make an opt-out form
 * unreachable in every environment that has not bought a Cloudflare account. An unreachable opt-out
 * is a worse compliance outcome than a spammable one. The keys are an open item in
 * `docs/prelaunch/progress.md`.
 *
 * **A verification Cloudflare cannot answer passes.** An outage at a captcha provider must not take
 * a statutory request form down with it; the throttle is what remains, and it is enough.
 */
final class Turnstile
{
    private const string ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Whether a challenge is configured at all — what a form asks before it renders the widget.
     */
    public function isEnabled(): bool
    {
        return $this->siteKey() !== null && $this->secret() !== null;
    }

    public function siteKey(): ?string
    {
        $key = (string) config('services.turnstile.site_key', '');

        return $key === '' ? null : $key;
    }

    /**
     * Whether this token is good. True when no challenge is configured.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = $this->secret();

        if ($secret === null) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post(self::ENDPOINT, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn (?string $value): bool => $value !== null));
        } catch (Throwable $e) {
            // The provider is unreachable. The throttle still applies; the form stays open.
            Log::warning('Turnstile verification unavailable.', ['exception' => $e::class]);

            return true;
        }

        if ($response->serverError()) {
            return true;
        }

        return $response->json('success') === true;
    }

    private function secret(): ?string
    {
        $secret = (string) config('services.turnstile.secret', '');

        return $secret === '' ? null : $secret;
    }
}
