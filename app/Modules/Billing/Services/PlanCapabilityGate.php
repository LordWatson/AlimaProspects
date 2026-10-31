<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;

/**
 * What a team's **plan** includes (Phase 19b, ADR 0015).
 *
 * The middle of the three capability gates, and the reason no other module has to import
 * `App\Modules\Billing` to find out what a plan contains (CLAUDE.md §3) — the same seam shape as
 * `CreditSource`, `LeadQuotaSource` and `SeatLimitSource`.
 *
 * It decorates rather than replaces, so a capability the price list says nothing about falls through
 * to Core's permissive default. That fall-through is the important behaviour: a plan with **no
 * `capabilities` key at all** entitles a team to everything, exactly as the product behaved before
 * this existed. A missing configuration is not a product decision, and the alternative — defaulting
 * to refuse — would switch six features off for every customer the moment this shipped.
 *
 * A plan with a `capabilities` key is read as **exhaustive**: anything not on the list is excluded.
 * That is what makes the list a plan definition rather than a set of additions.
 */
final class PlanCapabilityGate implements CapabilityGate
{
    public function __construct(
        private readonly CapabilityGate $inner,
        private readonly PlanResolver $plans,
    ) {}

    public function allows(Team $team, Capability $capability): bool
    {
        $declared = $this->declaredFor($team);

        if ($declared === null) {
            return $this->inner->allows($team, $capability);
        }

        return in_array($capability->value, $declared, true);
    }

    /**
     * The plan's capability list, or null when it declares none.
     *
     * Read from config by plan key rather than from `PlanData`, because the list is a *plan
     * definition* and `PlanData` is what the pricing screen renders — putting it on the DTO would
     * have put a permission next to the marketing copy it is deliberately separate from.
     *
     * @return list<string>|null
     */
    private function declaredFor(Team $team): ?array
    {
        $key = $this->plans->planFor($team)->key->value;

        /** @var mixed $declared */
        $declared = config('billing.plans.'.$key.'.capabilities');

        if (! is_array($declared)) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $declared),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
