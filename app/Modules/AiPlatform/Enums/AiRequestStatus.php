<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Enums;

/**
 * Outcome of a logged AI request (see the `ai_requests` ledger). A failed request is still logged
 * so a team's attempted usage and error rate are observable.
 */
enum AiRequestStatus: string
{
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
        };
    }
}
