<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Contracts\CrmConnector;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Signals\Services\SignalSourceRegistry;

/**
 * Resolves a {@see CrmConnector} for a {@see CrmProvider} (PL7, ADR 0034).
 *
 * The deliberate mirror of {@see SignalSourceRegistry} and of Lead Sourcing's and Enrichment's —
 * registered by a module service provider, keyed by the enum value, so `SyncLeadToCrmAction`
 * contains no `match` over providers. It is also what makes this phase testable at all: PL7 ships
 * no connector, and the whole framework is exercised by registering a fake one.
 *
 * **Registration and availability are the same question here**, unlike in Signals. A signal source
 * can be declared-but-unwired because a team stores a *key* against it and that key has to be
 * refused at the form; a CRM provider is only ever reached through a route or a stored
 * `integrations` row, and both ask this class. So {@see CrmProvider::isAvailable()} delegates here
 * rather than keeping a second list that could disagree.
 */
final class CrmConnectorRegistry
{
    /**
     * @var array<string, CrmConnector>
     */
    private array $connectors = [];

    public function register(CrmConnector $connector): void
    {
        $this->connectors[$connector->provider()->value] = $connector;
    }

    /**
     * The connector for a provider.
     *
     * @throws IntegrationException when this build has none
     */
    public function for(CrmProvider $provider): CrmConnector
    {
        return $this->connectors[$provider->value]
            ?? throw IntegrationException::connectorUnavailable($provider);
    }

    public function has(CrmProvider $provider): bool
    {
        return isset($this->connectors[$provider->value]);
    }

    /**
     * The provider for a connector, or null — for a caller that is deciding rather than acting.
     *
     * Separate from {@see self::for()} so the two readings are explicit at the call site: a member
     * asking to connect something unknown is an error, and a settings screen listing what is on
     * offer is not.
     */
    public function find(CrmProvider $provider): ?CrmConnector
    {
        return $this->connectors[$provider->value] ?? null;
    }
}
