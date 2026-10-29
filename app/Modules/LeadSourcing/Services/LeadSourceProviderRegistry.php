<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;

/**
 * Resolves a {@see LeadSourceProvider} for a given {@see LeadSource}. Providers are registered by
 * the module service provider (keyed by their enum value) so new sources (Phase 7b: Google Places,
 * web search) slot in without touching the discovery job or action — the factory seam ADR 0008
 * calls for. A fake provider can be registered here in tests to run the whole pipeline offline.
 */
final class LeadSourceProviderRegistry
{
    /**
     * @var array<string, LeadSourceProvider>
     */
    private array $providers = [];

    public function register(LeadSourceProvider $provider): void
    {
        $this->providers[$provider->source()->value] = $provider;
    }

    /**
     * The provider for a source, or a domain exception if none is registered.
     *
     * @throws LeadSourcingException
     */
    public function for(LeadSource $source): LeadSourceProvider
    {
        return $this->providers[$source->value]
            ?? throw LeadSourcingException::unknownProvider($source->value);
    }

    public function has(LeadSource $source): bool
    {
        return isset($this->providers[$source->value]);
    }
}
