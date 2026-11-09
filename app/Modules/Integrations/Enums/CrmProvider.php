<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

use App\Modules\Integrations\Services\CrmConnectorRegistry;

/**
 * The CRMs Alima can connect to (PL7).
 *
 * An **enum** rather than a row, by ADR 0018 §1's test: a class exists per provider, so the
 * application branches on the value. The registry is what maps a case to that class, exactly as
 * `SignalSourceKind` maps to a `SignalSourceProvider` (ADR 0023) and `LeadSource` to a
 * `LeadSourceProvider` (ADR 0008) — the fourth time that pair appears here, and the reason the
 * pattern is worth having: adding Pipedrive is a class and a registration, not a branch in a job.
 *
 * **A declared case is not a wired one.** Both cases are declared because PL7 builds the framework
 * and PL8/PL9 build the connectors, and the alternative — adding the case with the connector —
 * would mean a settings screen with nothing on it and no way to test the framework at all.
 * {@see self::isAvailable()} is the one question anything else may ask, and it asks the registry
 * rather than a hard-coded list, so a case cannot be "available" while nothing implements it.
 */
enum CrmProvider: string
{
    /** PL8. */
    case Pipedrive = 'pipedrive';

    /** PL9. */
    case HubSpot = 'hubspot';

    public function label(): string
    {
        return match ($this) {
            self::Pipedrive => 'Pipedrive',
            self::HubSpot => 'HubSpot',
        };
    }

    /**
     * Whether a connector for this provider is actually registered.
     *
     * Asked of the container rather than answered by a `match`, so the enum cannot claim a provider
     * the application has no code for. A screen offers the available ones; the OAuth routes refuse
     * the rest with a 404.
     */
    public function isAvailable(): bool
    {
        return app(CrmConnectorRegistry::class)->has($this);
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case): bool => $case->isAvailable()));
    }
}
