<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;

/**
 * Everything sales knows about a business they are onboarding (Phase 19b).
 *
 * Deliberately more than the minimum. A team provisioned with only a name and an owner lands its
 * new Owner on a blank onboarding wizard — which is the same thing self-serve signup gives them,
 * and throws away the hour somebody already spent on a call. The profile fields below are what
 * turns the first screen from "tell us about your business" into "we wrote this down, is it right?".
 *
 * `trialDays` is nullable with a meaningful null: null means **use the configured default**, and 0
 * means **no trial**, which is the right answer for an invoiced customer whose contract started on
 * Monday. They are not the same, so the field cannot be a plain int.
 */
final readonly class ProvisionTeamData
{
    /**
     * @param  list<string>  $capabilitiesOn  Capabilities to switch on for this tenant regardless of
     *                                        its plan — a pilot's Signals, say.
     */
    public function __construct(
        // --- The tenant ---
        public string $teamName,
        public Market $market,
        public string $timezone,
        public SupportedLocale $locale,

        // --- The owner ---
        public string $ownerEmail,
        public string $ownerName,

        // --- Billing ---
        public BillingMode $billingMode = BillingMode::Stripe,
        public ?PlanKey $grantPlan = null,
        public ?int $grantSeats = null,
        public ?int $grantLeads = null,
        public ?int $grantCredits = null,
        public ?string $grantNote = null,
        /** Null = the configured default; 0 = no trial at all. */
        public ?int $trialDays = null,

        // --- What sales already knows ---
        public ?string $companyWebsite = null,
        public ?string $industry = null,
        public ?string $valueProposition = null,
        public ?string $icpName = null,

        /** @var list<string> */
        public array $capabilitiesOn = [],
    ) {}
}
