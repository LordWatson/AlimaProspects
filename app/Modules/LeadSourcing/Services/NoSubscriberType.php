<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Contracts\SubscriberTypeResolver;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * The default {@see SubscriberTypeResolver}: no regime, nothing to say (PL5).
 *
 * Null rather than an "unknown" shape, so a deployment without the Compliance module shows no badge
 * at all instead of warning about every lead in the product on a rule it is not applying.
 */
final class NoSubscriberType implements SubscriberTypeResolver
{
    public function subscriberTypeFor(Lead $lead): ?array
    {
        return null;
    }
}
