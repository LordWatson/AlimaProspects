<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRejection;

/**
 * Why an article was not kept as a signal (S11), as remembered by {@see SignalRejection}.
 *
 * **An enum, and ADR 0018's test is why.** A signal *type* became a row because nothing in the app
 * branches on which one it is; this is the opposite case — the app does branch on a verdict, and
 * two of them are the two different conversations somebody tuning a definition has. "Not an event"
 * says the include/exclude rules on the type are letting round-ups through; "below threshold" says
 * the event is real and the floor is wrong. A closed set the code reasons about is an enum.
 *
 * There is deliberately **no case for an unoffered type**. When the model names a key that was not
 * offered, the item is dropped and logged (ADR 0018 §7) and nothing is remembered: that is a
 * statement about our prompt, not a verdict about the article, and caching it would skip an item a
 * corrected prompt would have kept.
 */
enum SignalRejectionVerdict: string
{
    /** The classifier said it is not one of the team's events at all — a round-up, a listicle. */
    case NotEvent = 'not_event';

    /**
     * A real event of a type the team watches, under that type's own confidence or relevance floor
     * ({@see SignalDefinition::effectiveThresholds()}).
     */
    case BelowThreshold = 'below_threshold';

    /**
     * What this verdict tells somebody tuning the type that produced it.
     *
     * A translation key, like every other label leaving this module: the screens are EN/AR.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::NotEvent => 'The classifier did not read this as one of your events.',
            self::BelowThreshold => 'A real event, under this type’s confidence or relevance floor.',
        };
    }
}
