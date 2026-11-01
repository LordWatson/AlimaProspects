<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\ProviderHealth;
use App\Modules\Core\Support\CircuitBreaker;
use Illuminate\Support\Facades\Log;

/**
 * Close a provider's circuit breaker by hand (Phase 19c).
 *
 * The lever for the minute after a provider recovers. The breaker's cooldown is seconds to a couple
 * of minutes by config, so this is not usually about waiting — it is about a provider that failed
 * for a reason we have since fixed (a rotated key, a quota raised) and an operator who does not
 * want the next cohort of jobs to be refused on the strength of the old failures.
 *
 * **No transaction, and nothing to roll back.** Breaker state is cache, not a row:
 * `CircuitBreaker::reset()` forgets two keys. Wrapping cache writes in a database transaction would
 * be theatre — the cache is not in it — and the audit entry is the durable half, so it is written
 * after the reset has actually happened.
 *
 * It calls {@see CircuitBreaker::reset()} rather than `recordSuccess()`,
 * which does the same thing: an operator resetting a breaker is not recording a success, nothing
 * succeeded, and the method an operations panel calls should not be writing a small lie into the
 * only place that knows how the provider is doing.
 *
 * Audited, because the breaker exists to protect a struggling provider from us — and the person who
 * overrode that protection, for which provider, is the first thing anybody asks when the provider
 * complains.
 */
final class ResetCircuitBreakerAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly ProviderHealth $health,
    ) {}

    public function handle(PlatformProvider $provider): void
    {
        $key = $provider->breakerKey();

        if ($key === null) {
            // Three providers have no breaker, and the enum says so rather than pretending.
            throw AdminException::providerNotInstrumented($provider->label());
        }

        $breaker = $this->health->breaker($provider);

        // Recorded before the reset, because afterwards it is zero — and "was it actually open?" is
        // the question that makes the entry worth having.
        $wasOpen = $breaker->isOpen($key);
        $failures = $breaker->failures($key);

        $breaker->reset($key);

        $this->audit->record(AdminAudit::BREAKER_RESET, null, [
            'provider' => $provider->value,
            'was_open' => $wasOpen,
            'failures_cleared' => $failures,
        ]);

        Log::channel('lead_sourcing')->warning('Circuit breaker reset from the admin panel.', [
            'provider' => $provider->value,
            'was_open' => $wasOpen,
            'failures_cleared' => $failures,
        ]);
    }
}
