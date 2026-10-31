<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Data\PlanGrantData;
use App\Modules\Admin\Data\ProvisionTeamData;
use App\Modules\Admin\Data\ProvisionTeamPlan;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboard a business from the panel (Phase 19b) — the Action behind the screen a sales person uses
 * after a call.
 *
 * It **calls Auth's own `CreateTeamAction`** rather than writing the team itself, and that is the
 * requirement rather than a preference: a team provisioned by sales has to be indistinguishable
 * from one that signed up. That Action is what adds the Owner through `TeamMembership`, writes the
 * `team.created` audit entry and fires the `Team::created` event Billing hangs the trial off.
 * Reimplementing it would be a second, slightly different way of becoming a customer.
 *
 * **An operator never chooses a customer's password.** A new owner gets a reset link and sets their
 * own — there is no field for a password here and no code path that would accept one.
 *
 * Two things about the trial are easy to get wrong, so they are explicit. Billing sets
 * `trial_ends_at` from a model event at creation, guarded on the column already being set; so a
 * **custom** trial is written afterwards, and **no** trial means writing null afterwards. Fighting
 * the event — suppressing it, or reordering around it — would mean a provisioned team's trial
 * behaved differently from a self-serve one, which is the whole thing this Action is avoiding.
 *
 * One transaction, every external call after it: the reset mail goes out in `DB::afterCommit`, so a
 * provisioning that rolls back cannot leave somebody holding a link to a team that does not exist.
 */
final class ProvisionTeamAction
{
    public function __construct(
        private readonly CreateTeamAction $createTeam,
        private readonly GrantPlanAction $grantPlan,
        private readonly SetBillingModeAction $setBillingMode,
        private readonly SetCapabilityOverrideAction $overrides,
        private readonly SendUserPasswordResetAction $sendPasswordReset,
        private readonly TeamContext $teamContext,
        private readonly AdminAudit $audit,
    ) {}

    /**
     * What provisioning **would** do, writing nothing.
     *
     * Not a nicety. The first real use of this screen is somebody typing a customer's name wrong,
     * and the second is discovering that the owner's address already belongs to an account — which
     * is a thing an operator must be told *before* a stranger gets an email inviting them into
     * somebody else's business, not after.
     */
    public function preview(ProvisionTeamData $data): ProvisionTeamPlan
    {
        $email = $this->normaliseEmail($data->ownerEmail);
        $existing = User::query()->where('email', $email)->first();

        return new ProvisionTeamPlan(
            teamName: $data->teamName,
            ownerEmail: $email,
            ownerExists: $existing instanceof User,
            ownerName: $existing instanceof User ? (string) $existing->name : $data->ownerName,
            ownerTeamCount: $existing instanceof User ? $existing->teams()->count() : 0,
            billingMode: $data->billingMode,
            grantPlan: $data->grantPlan,
            trialDays: $this->trialDays($data),
            willCreateProfile: $this->hasProfileData($data),
            willCreateIcp: $data->icpName !== null && $data->icpName !== '',
            capabilitiesOn: $this->capabilities($data),
        );
    }

    public function handle(ProvisionTeamData $data, User $operator): Team
    {
        $email = $this->normaliseEmail($data->ownerEmail);

        $isNewAccount = ! User::query()->where('email', $email)->exists();

        $team = DB::transaction(function () use ($data, $email, $operator, $isNewAccount): Team {
            $owner = $this->owner($email, $data->ownerName);

            // Auth's Action: the team, the Owner membership, the audit entry, the trial event.
            // `switchTo: false` because the owner may be an existing customer already working in
            // another team, and moving them out of it is not ours to do.
            $team = $this->createTeam->handle($owner, new CreateTeamData(
                name: $data->teamName,
                timezone: $data->timezone,
                locale: $data->locale->value,
                market: $data->market->value,
            ), switchTo: false);

            $this->applyTrial($team, $data);

            if ($data->billingMode !== BillingMode::Stripe) {
                $this->setBillingMode->handle($team, $data->billingMode, $operator);
            }

            if ($data->grantPlan !== null) {
                $this->grantPlan->handle($team, new PlanGrantData(
                    plan: $data->grantPlan,
                    note: $data->grantNote ?? 'Granted at provisioning.',
                    startsAt: CarbonImmutable::now('UTC'),
                    seats: $data->grantSeats,
                    leads: $data->grantLeads,
                    credits: $data->grantCredits,
                ), $operator);
            }

            foreach ($this->capabilities($data) as $capability) {
                $this->overrides->handle(
                    $team,
                    $capability,
                    enabled: true,
                    reason: 'Enabled at provisioning.',
                    expiresAt: null,
                    operator: $operator,
                );
            }

            $this->seedProfile($team, $data);

            // **The way in is a password-reset link, not a team invitation**, and the plan for
            // this phase had it the other way round.
            //
            // `InviteTeamMemberAction` exists to invite somebody to a team they are *not* in, and
            // it refuses an existing member — correctly. But `CreateTeamAction` has just made this
            // person the Owner, so an invitation here is refused as `alreadyMember` and takes the
            // whole provisioning down with it. Fighting that check would have meant writing the
            // invitation row by hand, bypassing the seat count and the audit entry that belong
            // with it.
            //
            // The account genuinely exists and genuinely needs a credential, which is what the
            // reset broker is for — so this calls **19a's own `SendUserPasswordResetAction`**
            // rather than the broker directly. That is deliberate: it is already the one way an
            // operator gives somebody a way in, it reports the broker's verdict instead of
            // assuming it, and it writes the `admin.user.password_reset_sent` entry that makes a
            // provisioned account's first credential as auditable as every later one. The rule it
            // serves is unchanged: an operator never chooses a customer's password.
            //
            // Only for a **new** account. An existing customer being added to a second team
            // already knows how to sign in, and a "set your password" mail would be nonsense.
            if ($isNewAccount) {
                DB::afterCommit(function () use ($owner, $operator): void {
                    $this->sendPasswordReset->handle($owner, $operator);
                });
            }

            $this->audit->record(
                'admin.team.provisioned',
                $team,
                [
                    // The id as well as the address. The address is what makes a provisioning
                    // auditable at all — "who did sales onboard?" has no other answer — but an id
                    // is what survives the owner changing their email.
                    'owner_id' => $owner->getKey(),
                    'owner_email' => $email,
                    'billing_mode' => $data->billingMode->value,
                    'grant_plan' => $data->grantPlan?->value,
                    'operator_id' => $operator->getKey(),
                ],
                (int) $team->getKey(),
            );

            return $team;
        });

        return $team->refresh();
    }

    /**
     * The owner: an existing account, or a new one with **no usable password**.
     *
     * `Str::random(64)` rather than a known value or an empty string: the account has to have
     * something in the column, and what it has must be a string nobody — including us — can sign in
     * with. The invitation is the only way in.
     */
    private function owner(string $email, string $name): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(64)),
            // Not verified: the invitation they accept is what proves they hold the address, and
            // marking it verified here would be us vouching for an address sales typed.
            'email_verified_at' => null,
        ])->save();

        return $user;
    }

    private function applyTrial(Team $team, ProvisionTeamData $data): void
    {
        $days = $this->trialDays($data);

        // The event has already set the configured default. Only write when the answer differs, so
        // the common case touches nothing.
        if ($days === null) {
            return;
        }

        $team->forceFill([
            'trial_ends_at' => $days > 0 ? CarbonImmutable::now('UTC')->addDays($days) : null,
        ])->save();
    }

    /**
     * The trial length to apply, or null to leave Billing's own default alone.
     */
    private function trialDays(ProvisionTeamData $data): ?int
    {
        if ($data->trialDays !== null) {
            return max(0, $data->trialDays);
        }

        // An invoiced customer's contract has already started; a trial on top of it is a free
        // fortnight nobody agreed to give away.
        return $data->billingMode === BillingMode::Manual ? 0 : null;
    }

    private function hasProfileData(ProvisionTeamData $data): bool
    {
        return $data->companyWebsite !== null
            || $data->industry !== null
            || $data->valueProposition !== null;
    }

    /**
     * Pre-fill what sales already knows, and mark onboarding **started rather than finished**.
     *
     * The distinction matters: a profile marked complete would skip the wizard, and the wizard is
     * where the Owner confirms what somebody else wrote down about their business. Landing them on
     * a half-filled form is the point.
     */
    private function seedProfile(Team $team, ProvisionTeamData $data): void
    {
        if (! $this->hasProfileData($data) && ($data->icpName === null || $data->icpName === '')) {
            return;
        }

        $this->teamContext->run($team, function () use ($team, $data): void {
            if ($this->hasProfileData($data)) {
                BusinessProfile::query()->create(array_filter([
                    'team_id' => $team->getKey(),
                    'company_name' => $data->teamName,
                    'website' => $data->companyWebsite,
                    'industry' => $data->industry,
                    'value_proposition' => $data->valueProposition,
                    'primary_market' => $data->market->value,
                ], static fn (mixed $value): bool => $value !== null));

                OnboardingProgress::query()->create([
                    'team_id' => $team->getKey(),
                    'current_step' => OnboardingStep::cases()[0]->value,
                ]);
            }

            if ($data->icpName !== null && $data->icpName !== '') {
                Icp::query()->create([
                    'team_id' => $team->getKey(),
                    'name' => $data->icpName,
                    'description' => 'Created at provisioning. Add criteria to start scoring.',
                    // **Not active.** An ICP with no criteria would score every lead the same, so
                    // activating it would make the first discovery run produce meaningless grades.
                    'is_active' => false,
                ]);
            }
        });
    }

    /**
     * @return list<Capability>
     */
    private function capabilities(ProvisionTeamData $data): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?Capability => Capability::tryFrom($value),
            $data->capabilitiesOn,
        )));
    }

    private function normaliseEmail(string $email): string
    {
        return Str::of($email)->trim()->lower()->value();
    }
}
