<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

/**
 * Where a CRM connection stands (PL7).
 *
 * Four states and only one of them pushes. The distinction that earns its keep is
 * `NeedsReauth` against `Error`: the first is **the customer's to fix** and the only cure is a
 * person clicking Reconnect, so it stops the sync and notifies the team's admins; the second is
 * ours or the provider's, is usually transient, and must not send anybody an email asking them to
 * re-authorise a connection that is perfectly valid.
 */
enum IntegrationStatus: string
{
    case Connected = 'connected';

    /** The token is dead and a refresh did not fix it. Only a person can clear this. */
    case NeedsReauth = 'needs_reauth';

    /** The last push failed for a reason that is not about the credentials. */
    case Error = 'error';

    /** A member disconnected it. The row stays so the links survive a reconnect. */
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NeedsReauth => 'Needs reconnecting',
            self::Error => 'Last sync failed',
            self::Disconnected => 'Not connected',
        };
    }

    /**
     * Whether a push may be attempted at all.
     *
     * `Error` is pushable: the previous attempt failed and the next one is how it recovers. The
     * other two are not, for different reasons — one has no usable credential, the other was
     * switched off on purpose.
     */
    public function canSync(): bool
    {
        return match ($this) {
            self::Connected, self::Error => true,
            self::NeedsReauth, self::Disconnected => false,
        };
    }
}
