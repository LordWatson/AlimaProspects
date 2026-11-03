<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;

/**
 * Resolves a {@see SignalSourceProvider} for a {@see SignalSourceKind} (S13, ADR 0023).
 *
 * The deliberate mirror of {@see LeadSourceProviderRegistry}: providers are registered by the module
 * service provider, keyed by their enum value, so a new source slots in without touching
 * `RunSignalWatchAction` — the factory seam ADR 0023 §1 calls for. A fake provider can be registered
 * here in tests to run the whole pipeline offline, which is what `FakeSignalSource` does.
 *
 * **Registration and availability are two different questions**, and the registry answers only the
 * first. {@see SignalSourceKind::isAvailable()} is what the save path and the form ask, so a
 * definition is never stored against a source with no implementation. The run asks this class
 * instead: by then the question is "is one registered *now*", because a key stored last week against
 * a source since withdrawn — or one whose credential has been pulled — must be skipped rather than
 * crash somebody's scheduled watch at three in the morning.
 */
final class SignalSourceRegistry
{
    /**
     * @var array<string, SignalSourceProvider>
     */
    private array $providers = [];

    public function register(SignalSourceProvider $provider): void
    {
        $this->providers[$provider->kind()->value] = $provider;
    }

    /**
     * The provider for a source, or a domain exception if none is registered.
     *
     * @throws SignalsException
     */
    public function for(SignalSourceKind $kind): SignalSourceProvider
    {
        return $this->providers[$kind->value]
            ?? throw SignalsException::unknownSource($kind->value);
    }

    public function has(SignalSourceKind $kind): bool
    {
        return isset($this->providers[$kind->value]);
    }

    /**
     * The provider for a source, or null when none is registered — the run's question.
     *
     * Separate from {@see self::for()} so the two readings are explicit at the call site: a member
     * naming an unknown source is an error worth raising, and a scheduled run meeting a withdrawn
     * one is a source to skip.
     */
    public function find(SignalSourceKind $kind): ?SignalSourceProvider
    {
        return $this->providers[$kind->value] ?? null;
    }

    /**
     * The kinds with a provider registered, in enum declaration order.
     *
     * @return list<SignalSourceKind>
     */
    public function registered(): array
    {
        return array_values(array_filter(
            SignalSourceKind::cases(),
            fn (SignalSourceKind $kind): bool => $this->has($kind),
        ));
    }

    /**
     * The sources this definition will actually run on: the kinds it names, that are registered, and
     * whose provider will serve it.
     *
     * The three filters are three different refusals and the run needs them collapsed into one list:
     * a key this build does not know (skipped), a key with no provider (skipped), and a provider that
     * declines the definition (`supports()` — no SIC codes, no feed URL). None of them is an error;
     * all of them mean "no queries from here".
     *
     * @return list<SignalSourceProvider>
     */
    public function forDefinition(SignalDefinition $definition): array
    {
        $providers = [];

        foreach ($definition->sourceKinds() as $kind) {
            $provider = $this->find($kind);

            if ($provider instanceof SignalSourceProvider && $provider->supports($definition)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
