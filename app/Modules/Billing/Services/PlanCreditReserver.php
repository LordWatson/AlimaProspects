<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentScope;

/**
 * The real {@see CreditReserver} (PL4), bound over Enrichment's permissive default.
 *
 * The **fifth** of the seams ADR 0012 established, and the same arrangement as the four before it:
 * Enrichment declares the contract with a default that works on its own, Billing binds this over
 * the top, and no module imports `App\Modules\Billing`.
 *
 * It is a thin mapping over {@see UsageAllowance}, and that is the point — the reserve/commit/
 * release semantics, the locking and the idempotency all live there, shared with every other
 * metered act. What this class owns is the one piece of knowledge Enrichment must not have:
 * **which allowance a scope spends**.
 *
 * `spentMicros` is deliberately ignored. The vendor cost is already on `enrichment_attempts`, per
 * call, and the margin guard reads it from there (ADR 0030) — writing it here too would make a
 * copy, and the copy would be the one that was wrong. What this settles is the team's **allowance**
 * in units, which is a different quantity answering a different question.
 */
final class PlanCreditReserver implements CreditReserver
{
    public function __construct(
        private readonly UsageAllowance $allowance,
        private readonly MarginGuard $margin,
    ) {}

    public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
    {
        $metric = $this->metricFor($scope);

        // The margin check comes **before** the hold, not after it: a refusal that had already
        // reserved would have to be unwound, and the way that fails is a hold nobody settles
        // sitting against the allowance until the month turns over.
        if ($this->margin->blocks($team, $metric)) {
            return CreditReservation::refuse($scope, 'billing.margin_guard.'.$metric->value);
        }

        $handle = $this->allowance->reserve($team, $metric, $units, meta: ['scope' => $scope->value]);

        if ($handle === null) {
            // A refusal, not an exception. A team at its ceiling is an ordinary answer the run
            // records and the screen explains; raising it would put a red light on the provider
            // panel for a vendor that answered perfectly well.
            return CreditReservation::refuse($scope, $this->refusalCodeFor($metric));
        }

        return CreditReservation::grant($scope, $units, $handle);
    }

    public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void
    {
        if ($reservation->reference === '') {
            return;
        }

        $this->allowance->commit($reservation->reference, $unitsUsed);
    }

    public function release(CreditReservation $reservation): void
    {
        if ($reservation->reference === '') {
            return;
        }

        $this->allowance->release($reservation->reference);
    }

    /**
     * Which allowance a scope spends.
     *
     * Four scopes, four meters, priced apart because they cost us wildly different amounts: a
     * mobile number is the expensive one and an address check is the cheap one, and a single
     * "enrichment credit" covering both would mean either giving away mobiles or charging for
     * verifications as though they were.
     */
    private function metricFor(EnrichmentScope $scope): BillingMetric
    {
        return match ($scope) {
            EnrichmentScope::People => BillingMetric::PeopleSearch,
            EnrichmentScope::Email => BillingMetric::EmailReveal,
            EnrichmentScope::Phone => BillingMetric::PhoneReveal,
            EnrichmentScope::Verify => BillingMetric::EmailVerification,
        };
    }

    /**
     * The code the run records, naming **which** allowance ran out.
     *
     * One code per metric rather than a single `allowance_spent`, because "you have used this
     * month's phone reveals" and "...your decision-maker searches" are different things to do about
     * it — and the billing screen can deep-link to the right top-up from the code alone.
     */
    private function refusalCodeFor(BillingMetric $metric): string
    {
        return 'billing.no_allowance.'.$metric->value;
    }
}
