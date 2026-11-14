<?php

declare(strict_types=1);

namespace App\Modules\ICP\Enums;

/**
 * Where a suggestion stands. `Declined` is kept rather than deleted on purpose: it is the record
 * that stops the same idea being proposed again.
 */
enum IcpSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
