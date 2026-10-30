<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\Onboarding\Enums\CompanySize;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Enums\ServedRegion;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A full, explorable demo tenancy so the app can be driven end-to-end locally.
 *
 * Builds two teams — one UK / English, one UAE / Arabic (so RTL + Tajawal + ar date/number
 * formatting are visible) — a cast of members spanning all four {@see TeamRole} values, and a
 * couple of pending/expired invitations. The headline account is **Alex Watson** so you can log
 * in immediately:
 *
 *   alex@user.com  /  password   (Owner of Alima UK, Admin of Alima Gulf)
 *
 * Each team also gets a quarter of prospecting history — an active ICP, a graded lead list spread
 * over the last twelve weeks, a populated pipeline, and (for the UAE team only, per ADR 0011) a
 * watch with signals — so the **dashboard has something to report**. Since Reporting reads real
 * rows, a demo tenancy without them lands on the first-run explainer and none of the charts can
 * be seen.
 *
 * Everything goes through the real Actions / {@see TeamMembership} so the demo data is identical
 * in shape to what registration and invitations produce (pivot role + spatie role kept in sync,
 * tenancy scoping honoured). Idempotent: re-running `db:seed` is a no-op once Alex exists.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'alex@user.com')->exists()) {
            $this->command?->info('DemoSeeder: demo data already present — skipping.');

            return;
        }

        /** @var CreateTeamAction $createTeam */
        $createTeam = app(CreateTeamAction::class);
        /** @var TeamMembership $membership */
        $membership = app(TeamMembership::class);
        /** @var TeamContext $teamContext */
        $teamContext = app(TeamContext::class);

        // --- People (every account: password = "password", email verified) --------------------
        $alex = $this->user('Alex Watson', 'alex@user.com');
        $layla = $this->user('Layla Hassan', 'layla@user.com');
        $sam = $this->user('Sam Carter', 'sam@user.com');
        $priya = $this->user('Priya Shah', 'priya@user.com');
        $omar = $this->user('Omar Farouk', 'omar@user.com');

        // --- Teams (created by their owner, as registration would) -----------------------------
        // Alex owns the UK / English team and lands here on login.
        $uk = $createTeam->handle($alex, new CreateTeamData(
            name: 'Alima UK',
            timezone: 'Europe/London',
            locale: 'en',
            market: 'uk',
        ), switchTo: true);

        // Layla owns the UAE / Arabic team — switch to it to experience RTL + Tajawal.
        $gulf = $createTeam->handle($layla, new CreateTeamData(
            name: 'Alima Gulf',
            timezone: 'Asia/Dubai',
            locale: 'ar',
            market: 'uae',
        ), switchTo: true);

        // --- Memberships spanning all four roles ----------------------------------------------
        DB::transaction(function () use ($membership, $uk, $gulf, $alex, $layla, $sam, $priya, $omar): void {
            // Alima UK: Alex=Owner (already), + Admin / Member / Read-only teammates.
            $membership->add($sam, $uk, TeamRole::Admin);
            $membership->add($priya, $uk, TeamRole::Member);
            $membership->add($omar, $uk, TeamRole::ReadOnly);
            $membership->add($layla, $uk, TeamRole::Member);

            // Alima Gulf: Layla=Owner (already), Alex=Admin (so he can switch + see RTL), Omar=Member.
            $membership->add($alex, $gulf, TeamRole::Admin);
            $membership->add($omar, $gulf, TeamRole::Member);
        });

        // --- Pending / expired invitations so the invitations UI isn't empty -------------------
        $this->invitation($teamContext, $uk, $alex, 'newteammate@user.com', TeamRole::Member);
        $this->invitation($teamContext, $uk, $alex, 'readonly.invite@user.com', TeamRole::ReadOnly, expired: true);
        $this->invitation($teamContext, $gulf, $layla, 'gulf.invite@user.com', TeamRole::Admin);

        // --- Completed onboarding + Business Profiles so demo logins land on the dashboard ------
        $this->businessProfile($teamContext, $uk, [
            'company_name' => 'Alima UK',
            'website' => 'https://alima.example',
            'industry' => 'Marketing & advertising',
            'company_size' => CompanySize::Small->value,
            'locations' => ['London, UK'],
            'regions_served' => [ServedRegion::Uk->value],
            'primary_market' => Market::Uk->value,
            'products_services' => ['Brand strategy', 'Paid media', 'Content'],
            'value_proposition' => 'We help UK brands grow with bold, data-led marketing.',
            'pricing_min' => 200000,
            'pricing_max' => 2000000,
            'pricing_currency' => Currency::Gbp->value,
            'customer_examples' => ['Northwind Traders', 'Contoso'],
        ]);

        $this->businessProfile($teamContext, $gulf, [
            'company_name' => 'Alima Gulf',
            'website' => 'https://alima-gulf.example',
            'industry' => 'Marketing & advertising',
            'company_size' => CompanySize::Medium->value,
            'locations' => ['Dubai, UAE'],
            'regions_served' => [ServedRegion::Uae->value],
            'primary_market' => Market::Uae->value,
            'products_services' => ['Social media', 'Influencer marketing', 'Events'],
            'value_proposition' => 'Bilingual campaigns that connect brands with Gulf audiences.',
            'pricing_min' => 500000,
            'pricing_max' => 5000000,
            'pricing_currency' => Currency::Aed->value,
            'customer_examples' => ['Desert Rose', 'Gulf Star'],
        ]);

        // --- Prospecting history, so the dashboard reports on something ------------------------
        $this->prospecting($teamContext, $uk, 'UK growth agencies', LeadSource::CompaniesHouse, 'GB');
        $this->prospecting($teamContext, $gulf, 'UAE hospitality', LeadSource::GooglePlaces, 'AE');

        // Signals are UAE-only in v1 (ADR 0011), so only the Gulf team gets a watch and a feed.
        $this->signals($teamContext, $gulf);

        $this->command?->info('DemoSeeder: seeded 5 users, 2 teams (UK/en + UAE/ar) and 3 invitations.');
        $this->command?->info('DemoSeeder: log in with  alex@user.com  /  password');
    }

    /**
     * Create a verified user whose password is "password".
     */
    private function user(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    /**
     * Create a completed Business Profile + onboarding progress for a team (scoped to it), as
     * finishing the wizard would leave them. Amounts are integer minor units.
     *
     * @param  array<string, mixed>  $data
     */
    private function businessProfile(TeamContext $teamContext, Team $team, array $data): void
    {
        $teamContext->run($team, function () use ($team, $data): void {
            BusinessProfile::query()->create([
                'team_id' => $team->getKey(),
                ...$data,
                'version' => 1,
                'completed_at' => now(),
            ]);

            OnboardingProgress::query()->create([
                'team_id' => $team->getKey(),
                'current_step' => OnboardingStep::Review->value,
                'completed_steps' => OnboardingStep::dataValues(),
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * A quarter of prospecting for one team: an active ICP, leads spread back over the twelve weeks
     * the dashboard trend covers, grades on most of them, and the rest of the list spread across
     * the default pipeline.
     *
     * The ages are deliberate, not random — `created_at` is what the trend buckets on, so leads
     * all created "now" would draw one spike and twelve empty weeks. The spread rises towards the
     * present so the chart has a direction to read.
     */
    private function prospecting(
        TeamContext $teamContext,
        Team $team,
        string $icpName,
        LeadSource $mainSource,
        string $country,
    ): void {
        /** @var list<int> $stageIds */
        $stageIds = app(EnsureDefaultPipelineStagesAction::class)
            ->handle($team)
            ->pluck('id')
            ->values()
            ->all();

        $teamContext->run($team, function () use ($team, $icpName, $mainSource, $country, $stageIds): void {
            $icp = Icp::factory()->active()->create([
                'team_id' => $team->getKey(),
                'name' => $icpName,
                'description' => 'The businesses we win most often.',
            ]);

            // Leads per week, oldest first: a quiet start, a dip, then a build.
            $perWeek = [2, 4, 3, 6, 5, 8, 7, 11, 9, 14, 12, 16];

            // Grades, in the proportion a real list tends to land in: a few strong fits, a long
            // tail of partial ones.
            $gradeMix = [
                ScoreGrade::A, ScoreGrade::B, ScoreGrade::B, ScoreGrade::C,
                ScoreGrade::C, ScoreGrade::C, ScoreGrade::D,
            ];

            $sources = [$mainSource, $mainSource, $mainSource, LeadSource::WebSearch, LeadSource::Import];

            $index = 0;

            foreach ($perWeek as $weeksAgo => $count) {
                $age = count($perWeek) - 1 - $weeksAgo;

                for ($i = 0; $i < $count; $i++) {
                    $graded = $index % 3 !== 2;

                    $lead = Lead::factory()->create([
                        'team_id' => $team->getKey(),
                        'source' => $sources[$index % count($sources)],
                        'status' => $graded ? LeadStatus::Scored : LeadStatus::Discovered,
                        'country' => $country,
                        // Every fifth lead is on the board, cycling through the stages so no
                        // column is empty and none holds the whole list.
                        'pipeline_stage_id' => $index % 5 === 0 && $stageIds !== []
                            ? $stageIds[intdiv($index, 5) % count($stageIds)]
                            : null,
                        'dedupe_key' => 'demo:'.Str::lower(Str::random(16)),
                    ]);

                    // Spread inside the week so a day-level view is not a single stack either.
                    $lead->forceFill([
                        'created_at' => now()->subWeeks($age)->subDays($i % 5)->subHours($i),
                    ])->saveQuietly();

                    if ($graded) {
                        $grade = $gradeMix[$index % count($gradeMix)];

                        LeadScore::factory()->create([
                            'team_id' => $team->getKey(),
                            'lead_id' => $lead->getKey(),
                            'icp_id' => $icp->getKey(),
                            'grade' => $grade,
                            'score' => match ($grade) {
                                ScoreGrade::A => 88,
                                ScoreGrade::B => 71,
                                ScoreGrade::C => 52,
                                ScoreGrade::D => 24,
                            },
                        ]);
                    }

                    $index++;
                }
            }
        });
    }

    /**
     * A Dubai watch and a small feed for the UAE team — one of each event type, plus a dismissed
     * one so the Dismissed tab (and the "undismissed only" rule the dashboard counts by) are both
     * visible.
     */
    private function signals(TeamContext $teamContext, Team $team): void
    {
        $teamContext->run($team, function () use ($team): void {
            $watch = SignalWatch::factory()->create([
                'team_id' => $team->getKey(),
                'name' => 'Dubai wealth events',
                'last_run_at' => now()->subHours(6),
            ]);

            $shared = ['team_id' => $team->getKey(), 'signal_watch_id' => $watch->getKey()];

            Signal::factory()->count(4)->create([...$shared, 'event_type' => SignalEventType::FundingRound]);
            Signal::factory()->count(2)->create([...$shared, 'event_type' => SignalEventType::Acquisition]);
            Signal::factory()->create([...$shared, 'event_type' => SignalEventType::Relocation]);
            Signal::factory()->create([
                ...$shared,
                'event_type' => SignalEventType::Acquisition,
                'status' => SignalStatus::Dismissed,
            ]);
        });
    }

    /**
     * Create a pending (or expired) invitation scoped to the given team, as the inviter.
     */
    private function invitation(
        TeamContext $teamContext,
        Team $team,
        User $inviter,
        string $email,
        TeamRole $role,
        bool $expired = false,
    ): TeamInvitation {
        return $teamContext->run($team, function () use ($team, $inviter, $email, $role, $expired): TeamInvitation {
            $factory = TeamInvitation::factory()->state([
                'team_id' => $team->getKey(),
                'invited_by' => $inviter->getKey(),
                'email' => $email,
                'role' => $role->value,
            ]);

            return ($expired ? $factory->expired() : $factory)->create();
        });
    }
}
