<?php

declare(strict_types=1);

namespace App\Modules\Signals\Events;

use App\Modules\Signals\Enums\SignalDismissReason;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired (after commit) when a member clears a signal off the feed (S12).
 *
 * S4 stored the dismiss reason and documented it as being kept "for tuning, not for display"; this
 * is the event that finally makes it mean something. A dismissal is the strongest negative signal
 * the product gets — stronger than a member simply not clicking — and it is what
 * `signal_source_stats` and the tuning advisor are built on.
 *
 * **Not broadcast**, unlike {@see SignalConverted}. A dismissal is already reflected in the
 * response the member's own request returns, and telling every other open tab that somebody
 * cleared a card would be noise in the one place the product has promised to keep quiet.
 *
 * Carries ids and the reason — never the headline or the person's name. The reason is an enum
 * case, not a sentence, so nothing here is a second copy of the article.
 */
final class SignalDismissed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $signalId,
        public readonly ?SignalDismissReason $reason = null,
    ) {}
}
