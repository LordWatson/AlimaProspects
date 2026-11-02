<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;

/**
 * The operator's levers over what a team can do (Phase 19b, ADR 0015) — the outermost of the three
 * capability gates.
 *
 * **The order is the feature**, and it is the one thing in this class worth reading twice:
 *
 * 1. **Suspension.** A suspended team is not a feature question. It beats everything, including an
 *    override written for it yesterday, because the whole point of suspending a tenant is that no
 *    earlier decision keeps it running.
 * 2. **The global kill switch.** For the afternoon a provider is down or a feature ships broken.
 *    It beats per-team overrides deliberately: an operator reaching for it is dealing with an
 *    incident, and having to hunt down every override first is how an incident becomes an outage.
 * 3. **The per-team override**, with its reason and its expiry — a pilot, a goodwill gesture, or a
 *    feature a customer asked us to switch off for them.
 * 4. **The plan**, via the gate this decorates (Billing's), and Core's permissive default behind
 *    that.
 *
 * Memoised per team for the life of the request or job, because the guards ask on every discovery
 * run and every AI call. The memo is dropped by the Actions that change an override, never by a
 * caller merely reading — the same contract `PlanResolver` has, for the same reason: this is
 * `scoped`, so a long-lived `queue:work` process gets a fresh instance per job rather than an
 * answer from three jobs ago.
 */
final class GovernedCapabilityGate implements CapabilityGate
{
    /**
     * @var array<string, bool>
     */
    private array $memo = [];

    public function __construct(
        private readonly CapabilityGate $inner,
        private readonly TeamSuspension $suspension,
        private readonly PlatformSettings $settings,
    ) {}

    public function allows(Team $team, Capability $capability): bool
    {
        $key = $team->getKey().':'.$capability->value;

        return $this->memo[$key] ??= $this->resolve($team, $capability);
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    private function resolve(Team $team, Capability $capability): bool
    {
        // 1. Suspension.
        if ($this->suspension->isSuspended($team)) {
            return false;
        }

        // 2. The global kill switch.
        if (in_array($capability->value, $this->settings->list('capabilities.disabled'), true)) {
            return false;
        }

        // 3. The team's own override, if one is still in force.
        $override = $this->overrideFor($team, $capability);

        if ($override instanceof CapabilityOverride) {
            return $override->enabled;
        }

        // 4. The plan, and Core's default behind it.
        return $this->inner->allows($team, $capability);
    }

    /**
     * Read without the tenant scope and with an explicit `team_id`, for the reason `PlanResolver`
     * reads subscriptions the same way: the guards run inside queued jobs and scheduled commands,
     * where there may be no active team in context at all — and a scope that silently matched
     * nothing would read as "no override", which is a wrong answer rather than a missing one.
     */
    private function overrideFor(Team $team, Capability $capability): ?CapabilityOverride
    {
        return CapabilityOverride::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('capability', $capability->value)
            ->inForce()
            ->first();
    }
}
