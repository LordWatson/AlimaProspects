<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;
use DateTimeInterface;

/**
 * Shared set-up for the Compliance tests, and the lawful-basis fixture the **Lead Sourcing** tests
 * need now that discovery is gated on a recorded assessment (Phase 15).
 *
 * A class rather than global test functions: these fixtures are needed across two modules' test
 * directories, and PSR-4 autoloading makes that explicit instead of depending on which test file
 * Pest happened to load first (same reasoning as {@see OutreachFixtures}).
 */
final class ComplianceFixtures
{
    /**
     * A verified Owner with their own team.
     *
     * @return array{User, Team}
     */
    public static function team(): array
    {
        $user = createUserWithTeam();

        return [$user, $user->currentTeam()->firstOrFail()];
    }

    /**
     * An **active, in-force** legitimate-interest assessment for the team's market — the state the
     * discovery gate requires (docs/compliance.md §2).
     *
     * Every test that expects discovery to run needs this, which is the point: before Phase 15 the
     * gate was a stub that passed everything, and a sourcing test that still passes without a
     * recorded basis would mean the gate was not wired in.
     */
    public static function activeLia(Team $team, ?Market $market = null): LiaRecord
    {
        $market ??= Market::tryFrom($team->market) ?? Market::Uk;

        return app(TeamContext::class)->run($team, fn (): LiaRecord => LiaRecord::factory()
            ->forMarket($market)
            ->create(['team_id' => $team->getKey()]));
    }

    /**
     * A retention policy with short windows, so a test can age a lead past them without inventing a
     * date two years ago.
     */
    public static function retention(Team $team, int $uncontactedDays = 30, ?int $contactedDays = null): RetentionPolicy
    {
        return app(TeamContext::class)->run($team, fn (): RetentionPolicy => RetentionPolicy::factory()
            ->windows($uncontactedDays, $contactedDays)
            ->create(['team_id' => $team->getKey()]));
    }

    /**
     * Age a lead, and everything that dates it, to a point in the past.
     *
     * `updated_at` has to be written with the timestamps off, or saving it would set it to now —
     * which is the one field the retention sweep's "quiet since" test reads first.
     */
    public static function ageLead(Lead $lead, DateTimeInterface $to): Lead
    {
        $lead->timestamps = false;
        $lead->forceFill(['created_at' => $to, 'updated_at' => $to])->save();
        $lead->timestamps = true;

        return $lead->refresh();
    }

    /**
     * A logged approach to a lead, so the retention sweep sees it as worked rather than untouched.
     */
    public static function attempt(Team $team, Lead $lead, DateTimeInterface $occurredAt, ?int $contactId = null): ContactAttempt
    {
        return app(TeamContext::class)->run($team, function () use ($team, $lead, $occurredAt, $contactId): ContactAttempt {
            $attempt = ContactAttempt::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'lead_contact_id' => $contactId,
                'channel' => OutreachChannel::Email,
                'outcome' => ContactOutcome::Pending,
                'occurred_at' => $occurredAt,
                'notes' => 'Spoke to Dana about their onboarding.',
            ]);

            // The timeline entry the sweep's "quiet since" test also reads. Written directly rather
            // than through the Action, because the Action would stamp it with `now()`.
            LeadActivity::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'type' => LeadActivityType::Contacted,
                'subject_type' => $attempt->getMorphClass(),
                'subject_id' => $attempt->getKey(),
                'properties' => ['channel' => OutreachChannel::Email->value, 'outcome' => ContactOutcome::Pending->value],
                'occurred_at' => $occurredAt,
            ]);

            return $attempt;
        });
    }

    /**
     * A second member of the team, in the given role, with it as their active team.
     */
    public static function member(Team $team, TeamRole $role): User
    {
        $member = User::factory()->create();

        app(TeamMembership::class)->add($member, $team, $role);
        $member->forceFill(['current_team_id' => $team->getKey()])->save();

        return $member;
    }
}
