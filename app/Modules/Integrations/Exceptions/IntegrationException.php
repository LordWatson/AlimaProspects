<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Modules\Integrations\Services\TokenRefresher;

/**
 * Domain errors in the integration framework (PL7).
 *
 * Five, and two of the splits are load-bearing.
 *
 * {@see self::unauthorized()} means *this credential was refused* — recoverable, and
 * {@see TokenRefresher} tries exactly once to fix it. {@see self::needsReauth()} means *and the
 * refresh did not work*, which only a person can clear, so it is the one that stops the sync and
 * notifies the team's admins. Collapsing them would either email a team about a token that
 * refreshed itself, or retry a dead credential until the backoff gave up.
 *
 * {@see self::endpointRefused()} is the second split, against a bare exception out of the same code
 * path: the guard refusing an address is **permanent**, and a host that merely failed to resolve is
 * **not**. See its own note.
 */
final class IntegrationException extends DomainException
{
    protected string $errorCode = 'integration.invalid';

    /**
     * The provider refused the credential. Refreshable, maybe.
     *
     * Thrown by a connector, caught by the refresher. 401 is the provider's own answer and it is
     * kept, because this one is never rendered to a member — it is either recovered from or
     * replaced by {@see self::needsReauth()}.
     */
    public static function unauthorized(CrmProvider $provider): self
    {
        $e = new self($provider->label().' refused the connection.');
        $e->errorCode = 'integration.unauthorized';
        $e->status = 401;

        return $e;
    }

    /**
     * The connection is dead and only a person can revive it.
     */
    public static function needsReauth(CrmProvider $provider): self
    {
        $e = new self(__('Your :provider connection needs reconnecting.', ['provider' => $provider->label()]));
        $e->errorCode = 'integration.needs_reauth';
        $e->status = 409;

        return $e;
    }

    /**
     * Nothing in this build can talk to that provider.
     *
     * 404 rather than 422: as far as the application is concerned the route for that provider does
     * not exist, and a 422 would imply the request was nearly right.
     */
    public static function connectorUnavailable(CrmProvider $provider): self
    {
        $e = new self($provider->label().' is not available yet.');
        $e->errorCode = 'integration.connector_unavailable';
        $e->status = 404;

        return $e;
    }

    /**
     * The SSRF guard will not let us post to this endpoint, and never will.
     *
     * Its own factory so {@see DeliverWebhookJob} can tell a
     * **permanent** refusal from a **transient** failure to resolve a host. Both used to arrive as
     * a bare `RuntimeException` and both gave up immediately, so a DNS blip burned a delivery and
     * counted toward the twenty that switch an endpoint off (found by review).
     *
     * `$reason` is one of `SafeUrl`'s own fixed strings, which name the rule that refused without
     * echoing the customer's URL — that is what makes it safe to log and to store.
     */
    public static function endpointRefused(string $reason): self
    {
        $e = new self('Alima cannot post to that address: '.$reason);
        $e->errorCode = 'integration.endpoint_refused';
        $e->status = 422;

        return $e;
    }

    /**
     * The `state` on an OAuth callback was absent, expired, already used, or somebody else's.
     *
     * One message for all four. A callback that distinguishes "expired" from "not yours" is a
     * callback that tells an attacker which of their guesses was closer, and the member's remedy is
     * the same in every case: start again from the button.
     */
    public static function invalidState(): self
    {
        $e = new self('That connection attempt has expired. Start again from the Integrations page.');
        $e->errorCode = 'integration.invalid_state';
        $e->status = 422;

        return $e;
    }
}
