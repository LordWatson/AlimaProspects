<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Shared set-up for the AI Insights tests. A class rather than global test functions, for the same
 * reason the Leads Workspace, Outreach and Notifications fixtures are: half a dozen files need
 * them, and PSR-4 makes that explicit instead of depending on Pest's load order.
 */
final class InsightFixtures
{
    /**
     * A verified member with their own team and an active ICP for leads to be graded against.
     *
     * @return array{User, Team, Icp}
     */
    public static function team(): array
    {
        return LeadsWorkspaceFixtures::team();
    }

    /**
     * A lead the team **started** and then went quiet on — the shape `FindStaleLeadsAction` looks
     * for: an approach on record (which is what "started" means) and a timeline whose last entry
     * is `$daysQuiet` days old.
     *
     * Both halves matter: without the attempt the lead is unworked rather than stale, and without
     * the old activity its last touch is its creation date.
     */
    public static function staleLead(Team $team, Icp $icp, int $daysQuiet = 30, ?ScoreGrade $grade = null): Lead
    {
        return self::staleLeadQuietSince($team, $icp, CarbonImmutable::now()->subDays($daysQuiet), $grade);
    }

    /**
     * The same lead, with its last touch at an exact instant — for the tests that are about where
     * the staleness boundary falls rather than about a lead being old.
     */
    public static function staleLeadQuietSince(
        Team $team,
        Icp $icp,
        DateTimeInterface $quietSince,
        ?ScoreGrade $grade = null,
    ): Lead {
        $quietSince = CarbonImmutable::parse($quietSince);

        return app(TeamContext::class)->run($team, function () use ($team, $icp, $quietSince, $grade): Lead {
            $lead = Lead::factory()->create([
                'team_id' => $team->getKey(),
                'created_at' => $quietSince,
            ]);

            ContactAttempt::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => $quietSince,
            ]);

            LeadActivity::factory()->type(LeadActivityType::Contacted)->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => $quietSince,
            ]);

            if ($grade instanceof ScoreGrade) {
                LeadScore::factory()->grade($grade, 90)->create([
                    'team_id' => $team->getKey(),
                    'lead_id' => $lead->getKey(),
                    'icp_id' => $icp->getKey(),
                ]);
            }

            return $lead;
        });
    }

    /**
     * A strong-fit lead nobody has approached — the shape `BuildContactTodayAction` looks for.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function uncontactedStrongLead(
        Team $team,
        Icp $icp,
        ScoreGrade $grade = ScoreGrade::A,
        int $score = 88,
        array $attributes = [],
    ): Lead {
        return LeadsWorkspaceFixtures::lead($team, $attributes, $icp, $grade, $score);
    }

    /**
     * A stored insight, for the feed, policy and retention tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function insight(Team $team, InsightType $type = InsightType::ContactToday, array $attributes = []): Insight
    {
        return app(TeamContext::class)->run($team, fn (): Insight => Insight::factory()
            ->ofType($type)
            ->create([...$attributes, 'team_id' => $team->getKey()]));
    }

    /**
     * The AI digest's response, shaped as `insights.periodic_digest` validates it.
     *
     * @param  list<array<string, mixed>>  $overrides
     * @return array<string, mixed>
     */
    public static function aiDigest(array $overrides = []): array
    {
        $insights = $overrides === [] ? [[
            'topic' => 'outreach',
            'title' => 'LinkedIn is replying and email is not',
            'body' => 'Six of eleven LinkedIn approaches came back this week against one of nine emails.',
            'severity' => 'action',
        ]] : $overrides;

        return ['insights' => $insights];
    }
}
