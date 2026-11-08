<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Contracts\EnrichmentProvider;
use App\Modules\Enrichment\Enums\ProviderCapability;
use InvalidArgumentException;

/**
 * Which vendors to try, for which question, in which order (PL2).
 *
 * The deliberate mirror of `LeadSourcing\Services\LeadSourceProviderRegistry` and
 * `Signals\Services\SignalSourceRegistry`: providers register themselves in the module's service
 * provider, and the **order comes from config** (`enrichment.waterfall.<capability>`) rather than
 * from the order they happened to be registered in. That separation is the point — the order is a
 * commercial decision about price and hit rate that an operator changes in `.env`, and it must not
 * be a line of PHP somebody has to find.
 *
 * Three rules it enforces, each for a failure that has happened to the two registries before it:
 *
 * 1. **A provider listed under the wrong capability is refused at registration**, not at the first
 *    call. A mis-registered vendor would otherwise fail inside a queued job, against a real lead,
 *    after being paid.
 * 2. **A disabled provider never reaches a waterfall.** A vendor with no API key configured
 *    removes itself (`isEnabled()`), so an unconfigured one is absent rather than an exception
 *    inside the loop — which is what makes `BILLING_ENFORCE_PLANS`-style switches safe here too.
 * 3. **A config key naming a provider nobody registered is ignored, loudly in the return value and
 *    silently at runtime.** A typo in a waterfall list must not take the whole capability down; the
 *    remaining providers still run, and {@see self::unknownIn()} is what a test and the admin
 *    health page ask to find out.
 */
final class EnrichmentProviderRegistry
{
    /**
     * Registered providers, keyed by capability then by provider key.
     *
     * @var array<string, array<string, EnrichmentProvider>>
     */
    private array $providers = [];

    public function register(EnrichmentProvider $provider): void
    {
        $capability = $provider->capability();
        $contract = $capability->contract();

        if (! $provider instanceof $contract) {
            // At registration — boot time, in a test, in CI — rather than mid-job.
            throw new InvalidArgumentException(sprintf(
                '%s claims the %s capability but does not implement %s.',
                $provider::class,
                $capability->value,
                $contract,
            ));
        }

        $this->providers[$capability->value][$provider->key()] = $provider;
    }

    /**
     * The enabled providers for this capability, **in the order config says to try them**.
     *
     * @return list<EnrichmentProvider>
     */
    public function for(ProviderCapability $capability): array
    {
        $registered = $this->providers[$capability->value] ?? [];

        $ordered = [];

        foreach ($this->orderFor($capability) as $key) {
            $provider = $registered[$key] ?? null;

            if ($provider instanceof EnrichmentProvider && $provider->isEnabled()) {
                $ordered[] = $provider;
            }
        }

        return $ordered;
    }

    /**
     * One provider by key, whether or not it is enabled and whatever the waterfall says.
     *
     * For the places that need a *named* provider rather than a waterfall: a re-verify of one
     * address against the vendor that produced it, and the admin panel's per-provider health.
     */
    public function get(string $key): ?EnrichmentProvider
    {
        foreach ($this->providers as $byKey) {
            if (isset($byKey[$key])) {
                return $byKey[$key];
            }
        }

        return null;
    }

    /**
     * Every registered provider, enabled or not — what the admin panel lists.
     *
     * @return list<EnrichmentProvider>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->providers as $byKey) {
            foreach ($byKey as $provider) {
                $all[] = $provider;
            }
        }

        return $all;
    }

    /**
     * Keys this capability's waterfall names that nothing has registered — a typo, or a provider
     * whose class was removed and whose config line was not.
     *
     * Returned rather than thrown so one bad line cannot take a capability down, and surfaced so
     * it cannot sit there unnoticed: `EnrichmentProviderRegistryTest` asserts it is empty for the
     * shipped config, which is what makes a typo a failing suite rather than a vendor that
     * silently never runs.
     *
     * @return list<string>
     */
    public function unknownIn(ProviderCapability $capability): array
    {
        $registered = $this->providers[$capability->value] ?? [];

        return array_values(array_filter(
            $this->orderFor($capability),
            static fn (string $key): bool => ! isset($registered[$key]),
        ));
    }

    /**
     * The configured order for a capability, narrowed to strings.
     *
     * @return list<string>
     */
    private function orderFor(ProviderCapability $capability): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config($capability->waterfallKey(), []);

        return array_values(array_filter(array_map(
            static fn (mixed $key): ?string => is_string($key) && trim($key) !== '' ? trim($key) : null,
            $configured,
        )));
    }
}
