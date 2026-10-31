<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Capability;

/**
 * What provisioning would do, before it does it (Phase 19b).
 *
 * The dry run's answer. Three of these fields exist because of one failure mode: the owner's
 * address already belonging to an account. `ownerExists` and `ownerTeamCount` are how the screen
 * can say "this will add an existing customer, who is already in 2 teams, to a third" — which is
 * occasionally exactly right and occasionally a typo that would email a stranger an invitation into
 * somebody else's business.
 */
final readonly class ProvisionTeamPlan
{
    /**
     * @param  list<Capability>  $capabilitiesOn
     */
    public function __construct(
        public string $teamName,
        public string $ownerEmail,
        public bool $ownerExists,
        public string $ownerName,
        public int $ownerTeamCount,
        public BillingMode $billingMode,
        public ?PlanKey $grantPlan,
        /** Null = Billing's configured default; 0 = no trial. */
        public ?int $trialDays,
        public bool $willCreateProfile,
        public bool $willCreateIcp,
        public array $capabilitiesOn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_name' => $this->teamName,
            'owner_email' => $this->ownerEmail,
            'owner_exists' => $this->ownerExists,
            'owner_name' => $this->ownerName,
            'owner_team_count' => $this->ownerTeamCount,
            'billing_mode' => $this->billingMode->value,
            'billing_mode_label' => $this->billingMode->label(),
            'grant_plan' => $this->grantPlan?->value,
            'trial_days' => $this->trialDays,
            'will_create_profile' => $this->willCreateProfile,
            'will_create_icp' => $this->willCreateIcp,
            'capabilities_on' => array_map(
                static fn (Capability $capability): string => $capability->value,
                $this->capabilitiesOn,
            ),
            // The sentence the screen leads with, assembled here so the dry run and the
            // confirmation cannot describe the same plan differently.
            'summary' => $this->summary(),
        ];
    }

    /**
     * The sentence the screen leads with, and the one the confirmation repeats.
     *
     * Public, and assembled here rather than in the Vue page, so the dry run and the confirmation
     * cannot describe the same plan differently — which is the whole point of having a dry run.
     */
    public function summary(): string
    {
        $parts = ['Create the tenant “'.$this->teamName.'”'];

        $parts[] = $this->ownerExists
            ? 'add the existing account '.$this->ownerEmail.' as its Owner'
            : 'create an account for '.$this->ownerEmail.' and invite them to set a password';

        // The "no plan granted" warning belongs on **both** non-Stripe modes. `billing_mode` does
        // not grant anything, so a tenant moved off Stripe without a grant is on the default plan —
        // and `disabled` is the mode where that is easiest to miss, because "no billing" sounds
        // like "nothing to pay" rather than "nothing bought".
        $noGrant = $this->grantPlan === null
            ? ' with no plan granted — they will be on the default plan'
            : ' on the '.$this->grantPlan->value.' plan';

        $parts[] = match ($this->billingMode) {
            BillingMode::Stripe => 'self-serve billing',
            BillingMode::Manual => 'invoiced billing'.$noGrant,
            BillingMode::Disabled => 'no billing'.$noGrant,
        };

        $parts[] = match (true) {
            $this->trialDays === null => 'the usual trial',
            $this->trialDays === 0 => 'no trial',
            default => 'a '.$this->trialDays.'-day trial',
        };

        return ucfirst(implode(', ', $parts)).'.';
    }
}
