<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Where a request for suggested signal types has got to (Signals v3 P2).
 */
enum SignalSuggestionStatus: string
{
    /** Asked for; the drafting call has not come back yet. */
    case Pending = 'pending';

    /** Drafted. Each suggestion then carries its own state while its sample search runs. */
    case Ready = 'ready';

    case Failed = 'failed';
}
