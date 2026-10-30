<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Outreach\Enums\OutreachChannel;

/**
 * Shared set-up for the Outreach tests. A class rather than global test functions: these fixtures
 * are needed by half a dozen files, and PSR-4 autoloading makes that explicit instead of depending
 * on which test file Pest happened to load first (same reasoning as
 * {@see LeadsWorkspaceFixtures}).
 */
final class OutreachFixtures
{
    /**
     * A verified member with their own team and a **completed business profile**, which is what the
     * sender identity needs — without it every send is refused for `no_sender_identity`, which is
     * correct behaviour and wrong for a test about something else.
     *
     * @return array{User, Team}
     */
    public static function team(string $companyName = 'Alima Test Co'): array
    {
        $user = createUserWithTeam();
        $team = $user->currentTeam()->firstOrFail();

        app(TeamContext::class)->run($team, fn (): BusinessProfile => BusinessProfile::factory()->create([
            'team_id' => $team->getKey(),
            'company_name' => $companyName,
            'website' => 'alima.test',
        ]));

        return [$user, $team];
    }

    /**
     * A lead on the team, with a contact carrying the given address unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{Lead, ?LeadContact}
     */
    public static function lead(
        Team $team,
        array $attributes = [],
        ?string $contactEmail = 'hello@acme.test',
        ?string $contactPhone = null,
        bool $roleBased = false,
    ): array {
        return app(TeamContext::class)->run($team, function () use (
            $team, $attributes, $contactEmail, $contactPhone, $roleBased
        ): array {
            $lead = Lead::factory()->create([
                'company_name' => 'Acme Ltd',
                'website' => 'acme.test',
                ...$attributes,
                'team_id' => $team->getKey(),
            ]);

            $contact = $contactEmail === null && $contactPhone === null
                ? null
                : LeadContact::factory()->create([
                    'team_id' => $team->getKey(),
                    'lead_id' => $lead->getKey(),
                    'name' => $roleBased ? null : 'Dana Buyer',
                    'title' => $roleBased ? null : 'Operations Director',
                    'email' => $contactEmail,
                    'phone' => $contactPhone,
                    'is_role_based' => $roleBased,
                ]);

            return [$lead, $contact];
        });
    }

    /**
     * A lead carrying the UAE prior-consent restriction — the one case in v1, and the reason the
     * guard exists. Written onto `raw` the way `ConvertSignalToLeadAction` does, so the tests
     * exercise the real key rather than a convenient stand-in.
     *
     * @return array{Lead, ?LeadContact}
     */
    public static function restrictedLead(Team $team, ?string $contactEmail = 'hello@acme.test'): array
    {
        return self::lead($team, [
            'raw' => [OutreachRestriction::RAW_KEY => OutreachRestriction::UaePriorConsent->value],
        ], $contactEmail);
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

    /**
     * Put a {@see FakeAiTransport} behind the {@see AiClient} and return it. External AI is always
     * faked (CLAUDE.md test rules).
     */
    public static function fakeAi(): FakeAiTransport
    {
        $fake = new FakeAiTransport;

        app()->instance(AiTransport::class, $fake);
        app()->forgetInstance(AiClient::class);

        return $fake;
    }

    /**
     * A schema-valid `outreach.draft_message` response.
     *
     * @param  list<string>  $bodies
     * @return array<string, mixed>
     */
    public static function aiDraft(
        OutreachChannel $channel = OutreachChannel::Email,
        array $bodies = ['Hello — worth 15 minutes?', 'Hello — a quick question.'],
        ?string $subject = 'A quick question',
    ): array {
        return [
            'subject' => $subject,
            'variants' => array_map(
                static fn (string $body): array => ['channel' => $channel->value, 'body' => $body],
                $bodies,
            ),
        ];
    }
}
