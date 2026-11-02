<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Billing\Services\PlanCapabilityGate;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AllCapabilitiesEnabled;

/**
 * Why a team can or cannot use each capability (Phase 19b, ADR 0015).
 *
 * The answer to the risk that ADR 0015 accepts in writing: four layers can each decide a
 * capability, and *"a product where somebody has to guess which is a product nobody can support"*.
 * This is what stops the guessing. For every capability it reports the resolved answer **and the
 * layer that decided it** — suspension, the platform kill switch, this team's override, the plan,
 * or nothing at all.
 *
 * It lives here rather than in the controller because assembling it is several queries and a
 * re-resolution of a gate, and CLAUDE.md §2 wants controllers to authorise, validate, call one
 * thing and return.
 *
 * **It does not re-implement the order.** `GovernedCapabilityGate` remains the only thing that
 * decides; this asks it for the answer and then asks the layers *individually* to attribute it. A
 * second implementation of the precedence would be a second thing to keep in step with the first,
 * and the one the product enforces is not this one.
 */
final readonly class CapabilityExplainer
{
    public function __construct(
        private CapabilityGate $gate,
        private PlatformSettings $settings,
        private TeamSuspension $suspension,
        private PlanResolver $plans,
    ) {}

    /**
     * One row per capability, in enum order.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Team $team): array
    {
        $overrides = CapabilityOverride::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->get()
            ->keyBy(static fn (CapabilityOverride $override): string => $override->capability->value);

        $suspended = $this->suspension->isSuspended($team);
        $killed = $this->settings->list('capabilities.disabled');

        // The plan's own answer, asked of a gate built over the permissive default alone. This is
        // what "if the override were cleared" means, which is the sentence the Clear button needs.
        $planGate = new PlanCapabilityGate(new AllCapabilitiesEnabled, $this->plans);

        return array_map(function (Capability $capability) use ($team, $overrides, $suspended, $killed, $planGate): array {
            /** @var CapabilityOverride|null $override */
            $override = $overrides->get($capability->value);

            $planAllows = $planGate->allows($team, $capability);

            return [
                'value' => $capability->value,
                'label' => $capability->label(),
                'enforced_at' => $capability->enforcedAt(),
                'allowed' => $this->gate->allows($team, $capability),
                // What the plan alone would say, so the screen can tell an operator what clearing
                // an override would actually do rather than making them work it out.
                'plan_allows' => $planAllows,
                'decided_by' => $this->decidedBy($capability, $suspended, $killed, $override),
                'override' => $override instanceof CapabilityOverride ? [
                    'enabled' => $override->enabled,
                    'reason' => $override->reason,
                    'expires_at' => $override->expires_at?->toIso8601String(),
                    'active' => $override->isActive(),
                ] : null,
            ];
        }, Capability::cases());
    }

    /**
     * The layer that settled it, named in the order the gate consults them.
     *
     * @param  list<string>  $killed
     */
    private function decidedBy(
        Capability $capability,
        bool $suspended,
        array $killed,
        ?CapabilityOverride $override,
    ): string {
        if ($suspended) {
            return 'suspension';
        }

        if (in_array($capability->value, $killed, true)) {
            return 'kill_switch';
        }

        if ($override instanceof CapabilityOverride && $override->isActive()) {
            return 'override';
        }

        // Nothing above had an opinion, so it is the plan's answer — or, when the plan declares no
        // capability list at all, Core's permissive default that the plan gate falls through to.
        // Those two are deliberately reported as one: from an operator's chair the next place to
        // look is `config/billing.php` either way.
        return 'plan';
    }
}
