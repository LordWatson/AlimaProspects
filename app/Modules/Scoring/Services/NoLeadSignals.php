<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Services;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Contracts\LeadSignalSource;

/**
 * The default {@see LeadSignalSource}: this module knows nothing about what a lead has been doing.
 *
 * Scoring binds this and Signals replaces it, so a build without Signals — or a test that does not
 * care — grades exactly as it did before Q3: every buying-signal criterion `Unknown`, which is a
 * true statement rather than a miss.
 */
final class NoLeadSignals implements LeadSignalSource
{
    /**
     * @return list<string>
     */
    public function labelsFor(Lead $lead): array
    {
        return [];
    }
}
