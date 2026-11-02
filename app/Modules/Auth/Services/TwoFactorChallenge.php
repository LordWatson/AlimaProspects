<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\TwoFactorRequiredException;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * The second factor, for a caller with no session (Phase 18).
 *
 * Fortify's own challenge is a two-request dance over the session: `/login` parks the user's id in
 * `login.id` and redirects, `/two-factor-challenge` reads it back. A phone has neither the session
 * nor the redirect, so the exchange at `POST /auth/token` has to settle the password **and** the
 * second factor inside one request — which is safe for exactly the reason the session existed: the
 * password is re-presented alongside the code, so possession of a half-finished challenge is not
 * itself a credential and there is no intermediate state for anyone to steal.
 *
 * Everything about *what counts as a valid code* is still Fortify's: the same
 * {@see TwoFactorAuthenticationProvider} (so the configured window, algorithm and replay cache
 * apply), the same encrypter, the same one-time recovery codes, replaced as they are spent.
 *
 * **It carries its own throttle, per account.** The route's `auth-token` limiter is keyed on
 * email + IP, which is right for guessing a *password* — a password is long, and rotating IPs to
 * get more guesses is not much of an advantage. It is the wrong shape for a six-digit code, where
 * an attacker who already has the password has a million-guess space and every rotated IP buys
 * another five tries. So failures are counted against the **user**, where no amount of rotating
 * helps. Nothing else in the app has this problem, which is why the limiter lives here rather than
 * on the route.
 */
final class TwoFactorChallenge
{
    /**
     * Failed second factors tolerated per account, and over what window.
     *
     * Ten is generous for someone reading a code off their own phone and far short of useful for
     * someone guessing: at ten per quarter of an hour, a million-code space takes decades. The
     * window is long rather than short on purpose — a one-minute window an attacker simply waits
     * out is a limiter that only inconveniences the member.
     */
    private const int MAX_FAILURES = 10;

    private const int DECAY_SECONDS = 900;

    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function isEnabledFor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /**
     * Refuse before verifying, when this account has had too many failures.
     *
     * Called **outside** the exchange's transaction, and so is {@see recordFailure} — the default
     * cache store is the database, so a count written inside a transaction that then rolls back
     * would be rolled back with it, and every failed guess would be free.
     *
     * @throws ThrottleRequestsException
     */
    public function assertNotLockedOut(User $user): void
    {
        if (! $this->isEnabledFor($user)) {
            return;
        }

        $key = $this->throttleKey($user);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        Log::warning('Two-factor exchange locked out.', [
            'user_id' => $user->getKey(),
            'available_in' => $seconds,
        ]);

        throw new ThrottleRequestsException(
            __('Too many attempts — try again in :seconds seconds.', ['seconds' => $seconds]),
            null,
            ['Retry-After' => (string) $seconds],
        );
    }

    /**
     * Count one failed second factor against the account. See {@see assertNotLockedOut} for why
     * the caller does this outside the transaction rather than this class doing it inline.
     */
    public function recordFailure(User $user): void
    {
        if (! $this->isEnabledFor($user)) {
            return;
        }

        RateLimiter::hit($this->throttleKey($user), self::DECAY_SECONDS);

        // The user id, never the code. A log that records what was guessed is a log that records
        // what to guess next.
        Log::warning('Two-factor exchange failed.', ['user_id' => $user->getKey()]);
    }

    /**
     * Settle the challenge, or refuse.
     *
     * Runs **inside** the exchange's transaction, so a recovery code spent here is rolled back if
     * minting the token then fails — otherwise a one-time code could be burnt without a token
     * coming out of it.
     *
     * @throws TwoFactorRequiredException when the account needs a second factor and none was sent
     * @throws ValidationException when one was sent and it was wrong
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (! $this->isEnabledFor($user)) {
            return;
        }

        $code = $this->normalise($code);
        $recoveryCode = $this->normalise($recoveryCode);

        if ($code === null && $recoveryCode === null) {
            throw TwoFactorRequiredException::withMethods(['code', 'recovery_code']);
        }

        // `code` wins when both are sent. They are not interchangeable — one is reusable within
        // its window and one is spent for ever — so a caller that sends both must not have the
        // expensive one consumed because the cheap one happened to be wrong.
        if ($code !== null) {
            if ($this->codeMatches($user, $code)) {
                return;
            }

            throw ValidationException::withMessages([
                'code' => [__('That code did not match. Try the next one your app shows.')],
            ]);
        }

        $this->spendRecoveryCode($user, (string) $recoveryCode);
    }

    /**
     * Match and consume one recovery code, under a row lock.
     *
     * The lock is what makes it one-time under concurrency. Fortify's `replaceRecoveryCode` is a
     * read-modify-write on an in-memory model, so two requests presenting the same code can both
     * pass the match before either saves — and one code mints two tokens. Re-reading the row
     * `FOR UPDATE` inside the transaction serialises them, and the loser finds the code gone.
     */
    private function spendRecoveryCode(User $user, string $candidate): void
    {
        DB::transaction(function () use ($user, $candidate): void {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $stored = $this->matchingRecoveryCode($locked, $candidate);

            if ($stored === null) {
                throw ValidationException::withMessages([
                    'recovery_code' => [__('That recovery code is not one of yours, or it has been used already.')],
                ]);
            }

            // Spent on use, and replaced so the member keeps a full set — the same contract as the
            // web challenge. A recovery code that still worked a second time would be a password
            // that never expires, written on a piece of paper.
            $locked->replaceRecoveryCode($stored);

            // Worth an audit row of its own: a recovery code is the credential someone falls back
            // to when they have lost their authenticator, so "one was used, and when" is a
            // question that gets asked after an incident. The code itself is never recorded.
            $this->audit->log('two_factor.recovery_code_used', $user, [
                'remaining' => count($locked->recoveryCodes()),
            ], $user);

            // Keep the caller's instance in step with the row we just rewrote, so a second read
            // of its codes in the same request is not stale.
            $user->forceFill([
                'two_factor_recovery_codes' => $locked->two_factor_recovery_codes,
            ])->syncOriginal();
        });
    }

    private function codeMatches(User $user, string $code): bool
    {
        return (bool) $this->provider->verify(
            Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
            $code,
        );
    }

    /**
     * The stored code this one answers, or null. Compared with {@see hash_equals} so a near miss
     * costs the same as a wild guess.
     */
    private function matchingRecoveryCode(User $user, string $candidate): ?string
    {
        foreach ($user->recoveryCodes() as $stored) {
            if (hash_equals((string) $stored, $candidate)) {
                return (string) $stored;
            }
        }

        return null;
    }

    /**
     * Treat an absent, empty or whitespace-only value as "not sent" — a client that posts
     * `"code": ""` because its input is untouched means the same thing as one that posts nothing,
     * and should get the challenge rather than "that code did not match".
     */
    private function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function throttleKey(User $user): string
    {
        return 'two-factor-exchange:'.$user->getKey();
    }
}
