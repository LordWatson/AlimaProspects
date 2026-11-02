<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Http\Middleware\RestrictImpersonatedSession;
use App\Modules\Core\Support\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Signing in as a customer, and signing back out (Phase 19a).
 *
 * Impersonation is the most useful thing in the panel and the most dangerous. The design is
 * therefore built around what it is *not* allowed to do:
 *
 * - **It is not a way to act on a customer's behalf.** An impersonated session may not send
 *   outreach, start a checkout, change a plan, erase data, issue a token or change a password or
 *   email — see `config('admin.impersonation.blocked_routes')` and
 *   {@see RestrictImpersonatedSession}. An operator in a
 *   customer's seat emailing a prospect is a message the customer did not write, from their domain,
 *   to a real person, and it would land in their suppression and consent record as though they had.
 * - **It is not a way to become another operator.** Impersonating a platform admin would put their
 *   powers behind this session with their name on the trail, so it is refused outright.
 * - **It does not outlast the job.** The session carries an expiry (`ADMIN_IMPERSONATION_TTL`,
 *   default 60 minutes) and ends on sign-out. A forgotten impersonation is a customer's account
 *   left open in somebody's browser.
 * - **It is never silent.** Start and stop are audited against the *tenant*, a banner is on every
 *   screen, and `impersonated_by` rides in {@see AuditContext} so that every write the session
 *   makes — through whichever module's Action — records the operator behind the customer.
 *
 * The session keys are read by middleware on every web request, so they are plain scalars: an
 * object in the session would have to deserialise before the guard that depends on it can run.
 */
final class Impersonator
{
    public const string SESSION_OPERATOR = 'admin.impersonation.operator_id';

    public const string SESSION_EXPIRES = 'admin.impersonation.expires_at';

    public const string SESSION_TARGET = 'admin.impersonation.target_id';

    public function __construct(
        private readonly Session $session,
        private readonly AdminAudit $audit,
        private readonly AuditContext $context,
    ) {}

    /**
     * @throws AdminException
     */
    public function start(User $operator, User $target): void
    {
        if ($operator->getKey() === $target->getKey()) {
            throw AdminException::cannotImpersonateSelf();
        }

        if ($target->isPlatformAdmin()) {
            throw AdminException::cannotImpersonateAdmin();
        }

        $expiresAt = CarbonImmutable::now('UTC')->addMinutes($this->ttlMinutes());

        // Audited against the **tenant** the operator is about to be inside, not against nothing:
        // the team whose data is about to be read is the one whose audit view must show this.
        $this->audit->record(
            'admin.impersonation.started',
            $target,
            [
                'operator_id' => $operator->getKey(),
                'target_user_id' => $target->getKey(),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            $target->currentTeamId(),
        );

        Auth::guard('web')->login($target);

        // A fresh session id for the impersonated session, keeping the data: the operator's own
        // session identifier should not also address the customer's.
        $this->session->migrate(destroy: false);

        // The session's data is carried over, which includes the operator's own
        // `password_confirmed_at` — so without this the impersonated session would count as
        // password-confirmed for anything behind `RequirePassword`. Those routes are on the blocked
        // list, so it is latent rather than live, and a latent privilege is still one to remove.
        $this->session->forget('auth.password_confirmed_at');

        $this->session->put(self::SESSION_OPERATOR, (int) $operator->getKey());
        $this->session->put(self::SESSION_TARGET, (int) $target->getKey());
        $this->session->put(self::SESSION_EXPIRES, $expiresAt->getTimestamp());

        $this->annotateAudit();
    }

    /**
     * Hand the session back to the operator.
     *
     * @return User the operator, so the caller can redirect them somewhere that makes sense
     *
     * @throws AdminException
     */
    public function stop(): User
    {
        $operatorId = $this->operatorId();

        if ($operatorId === null) {
            throw AdminException::notImpersonating();
        }

        $operator = User::query()->find($operatorId);
        $targetId = $this->targetId();

        // Captured **before** the session is handed back, because it is the tenant the entry has to
        // be stamped with: `started` is recorded against the customer's team, and a `stopped` with
        // no team would leave their own audit view showing somebody arriving and never leaving.
        $targetTeamId = $this->targetTeamId();

        $this->forget();

        if (! $operator instanceof User) {
            // The operator's account was deleted mid-session. Ending the session is the only safe
            // answer — an impersonation with nobody behind it — but it is ended by **destroying the
            // session**, not by `Auth::logout()`: that would cycle the *customer's* remember token
            // and sign them out of their own remembered devices. See `expire()`.
            $this->abandonSession();

            throw AdminException::notImpersonating();
        }

        Auth::guard('web')->login($operator);
        $this->session->migrate(destroy: false);

        $this->audit->record(
            'admin.impersonation.stopped',
            $operator,
            ['operator_id' => $operator->getKey(), 'target_user_id' => $targetId],
            $targetTeamId,
        );

        return $operator;
    }

    /**
     * End an expired session and hand the operator back to themselves.
     *
     * Audited as `expired` rather than `stopped`, because nobody stopped it — the clock did.
     *
     * **It does not call `Auth::logout()`,** and that is the point of the method rather than an
     * implementation detail. At this moment the authenticated user is the *customer*, and
     * `SessionGuard::logout()` cycles the authenticated user's `remember_token` — so logging out
     * here would sign the real customer out of every device they had ticked "remember me" on,
     * because an operator left a tab open. Restoring the operator ends the access the TTL exists to
     * end, leaves the customer untouched, and is better for the operator too.
     *
     * @return User|null the operator, or null when their account has gone and the session had to be
     *                   abandoned instead
     */
    public function expire(): ?User
    {
        $this->audit->record('admin.impersonation.expired', null, [
            'operator_id' => $this->operatorId(),
            'target_user_id' => $this->targetId(),
        ], $this->targetTeamId());

        $operator = User::query()->find($this->operatorId());

        $this->forget();

        if (! $operator instanceof User) {
            $this->abandonSession();

            return null;
        }

        Auth::guard('web')->login($operator);
        $this->session->migrate(destroy: false);

        return $operator;
    }

    /**
     * Destroy the session without touching the authenticated user.
     *
     * The alternative — `Auth::logout()` — cycles the *customer's* `remember_token`, which is
     * somebody else's credential and none of our business to rotate. Destroying the session is
     * enough: the next request has no authenticated user.
     */
    private function abandonSession(): void
    {
        Auth::guard('web')->forgetUser();
        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    public function isActive(): bool
    {
        return $this->operatorId() !== null;
    }

    public function hasExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function operatorId(): ?int
    {
        $value = $this->session->get(self::SESSION_OPERATOR);

        return is_int($value) ? $value : null;
    }

    public function targetId(): ?int
    {
        $value = $this->session->get(self::SESSION_TARGET);

        return is_int($value) ? $value : null;
    }

    /**
     * The tenant the impersonated customer is working in, for stamping an audit entry.
     */
    private function targetTeamId(): ?int
    {
        $targetId = $this->targetId();

        if ($targetId === null) {
            return null;
        }

        return User::query()->find($targetId)?->currentTeamId();
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->session->get(self::SESSION_EXPIRES);

        return is_int($value) ? CarbonImmutable::createFromTimestamp($value, 'UTC') : null;
    }

    /**
     * Push `impersonated_by` into the ambient audit context, so every write this session makes —
     * through whichever module's Action, none of which know impersonation exists — records the
     * operator behind the customer. See {@see AuditContext}.
     */
    public function annotateAudit(): void
    {
        $operatorId = $this->operatorId();

        if ($operatorId !== null) {
            $this->context->add(['impersonated_by' => $operatorId]);
        }
    }

    /**
     * The banner payload. Shared on every tenant screen, so an operator can never be in a
     * customer's account without the page saying so.
     *
     * @return array<string, mixed>|null
     */
    public function banner(): ?array
    {
        if (! $this->isActive()) {
            return null;
        }

        $operator = User::query()->find($this->operatorId());

        return [
            'active' => true,
            'operator' => $operator instanceof User ? $operator->getAttribute('name') : null,
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'stop_url' => route('impersonation.stop'),
        ];
    }

    private function forget(): void
    {
        $this->session->forget([self::SESSION_OPERATOR, self::SESSION_TARGET, self::SESSION_EXPIRES]);
    }

    private function ttlMinutes(): int
    {
        $minutes = config('admin.impersonation.ttl_minutes', 60);

        return max(1, is_numeric($minutes) ? (int) $minutes : 60);
    }

    /**
     * Whether a route is on the blocked list. Patterns are route **names**, matched with
     * {@see Str::is()} so a whole module can be fenced off with `billing.*` rather than eleven
     * lines that drift the moment somebody adds a twelfth route.
     */
    public function blocks(?string $routeName): bool
    {
        if ($routeName === null || ! $this->isActive()) {
            return false;
        }

        /** @var mixed $patterns */
        $patterns = config('admin.impersonation.blocked_routes', []);

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
