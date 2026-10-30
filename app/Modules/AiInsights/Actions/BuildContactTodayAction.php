<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Actions;

use App\Modules\AiInsights\Data\InsightDraft;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "leads to contact today" digest (Phase 14): the short list worth approaching now.
 *
 * Candidates are the team's **strong fits that nobody has approached yet** — graded A or B against
 * the active ICP, not excluded by scoring, not in a Won or Lost stage, no contact attempt on
 * record — best fit first.
 *
 * ### Why the Outreach guard is asked, and what it is asked
 *
 * A list that recommends approaching a company the team may not approach is not a helpful
 * suggestion with a caveat; it is the platform inviting a member to break the rule it exists to
 * enforce. So every candidate goes past {@see OutreachGuard}, and the answer is read the way
 * Outreach itself reads it (docs/modules/outreach.md):
 *
 * - A **compliance** refusal on every channel — an opt-out, a prior-consent restriction with
 *   nothing permitted left — drops the lead from the list entirely.
 * - A **configuration** refusal (no contact chosen yet, no verified sender, sending switched off)
 *   does **not**. Those are the team's own setup, they change by teatime, and a lead hidden
 *   because nobody has verified a sender address yet is a lead the team never hears about.
 * - A lead whose only open channels are non-direct-marketing ones (a LinkedIn introduction, a
 *   referral) is kept and marked `restricted`, so the card says "introduction only" rather than
 *   silently suggesting an email that would be blocked.
 *
 * That third case is the common one, and it is worth being explicit about which leads land in it:
 * both a **prior-consent restriction** (the UAE case, ADR 0011) and a **suppression** — somebody
 * who opted out, or a company whose whole domain the team has been asked to stop emailing. Outreach
 * treats a LinkedIn message as the member's own act on a platform where the other person chooses
 * whether to answer, so it stays open in both cases. The answer is taken as it comes rather than
 * second-guessed: an insight that decided for itself that a suppressed company should disappear
 * from the list would have re-implemented the gate, badly, which is what
 * {@see OutreachDecision} warns against. Which means the "drop the lead
 * entirely" branch above does not fire for anything v1 can produce; it is what keeps this list
 * correct when a market arrives whose restriction closes an introduction too.
 *
 * The decision is **not** cached onto the insight as permission. It is a snapshot for the card's
 * wording; the guard is asked again, for real, when a draft is written and again when it is sent.
 */
final readonly class BuildContactTodayAction
{
    /**
     * How many candidates to read per place on the list. The guard can only be asked about a lead
     * we have in hand, so the query over-fetches a little rather than coming back short when a few
     * are suppressed.
     */
    private const int OVERFETCH = 4;

    public function __construct(
        private TeamContext $teamContext,
        private OutreachGuard $guard,
    ) {}

    /**
     * @param  string  $dateKey  The team's local `Y-m-d`, which the dedupe key is built from.
     */
    public function handle(Team $team, string $dateKey, ?DateTimeInterface $now = null): ?InsightDraft
    {
        $maxItems = max(1, (int) config('ai-insights.contact_today.max_items', 5));
        $asOf = CarbonImmutable::parse($now ?? CarbonImmutable::now())->utc();

        // The guard's own queries are inside this context too, not just the candidate read. They
        // are explicit about `team_id` so nothing leaks either way, but an Action that is only
        // correct when its caller happens to have set a context is a trap for the next caller.
        $items = $this->teamContext->run($team, function () use ($team, $maxItems): array {
            $candidates = $this->candidates($team)
                ->limit($maxItems * self::OVERFETCH)
                ->get();

            $items = [];

            foreach ($candidates as $lead) {
                $approach = $this->approachFor($team, $lead);

                if ($approach === null) {
                    continue;
                }

                $items[] = $this->item($lead, $approach);

                if (count($items) === $maxItems) {
                    break;
                }
            }

            return $items;
        });

        if ($items === []) {
            return null;
        }

        return new InsightDraft(
            type: InsightType::ContactToday,
            severity: InsightType::ContactToday->defaultSeverity(),
            payload: ['count' => count($items), 'leads' => $items],
            dedupeKey: InsightType::ContactToday->value.':'.$dateKey,
            periodEnd: $asOf,
        );
    }

    /**
     * Strong fits nobody has approached, best first.
     *
     * @return Builder<Lead>
     */
    private function candidates(Team $team): Builder
    {
        return Lead::query()
            // Scoped twice: inside the team context's global scope and explicitly (ADR 0006).
            ->where('leads.team_id', $team->getKey())
            ->whereDoesntHave('contactAttempts')
            ->whereDoesntHave(
                'pipelineStage',
                fn (Builder $stage): Builder => $stage->whereIn('outcome', [
                    StageOutcome::Won->value,
                    StageOutcome::Lost->value,
                ]),
            )
            ->whereHas('activeScore', fn (Builder $score): Builder => $score
                ->where('excluded', false)
                ->whereIn('grade', [ScoreGrade::A->value, ScoreGrade::B->value]))
            ->with('activeScore')
            // `leftJoin` would be the cheaper sort, and would also multiply a lead by its scores.
            // A correlated sub-select keeps one row per lead, which is what a shortlist of five
            // needs to be right about.
            ->orderByDesc(
                LeadScore::query()
                    ->select('score')
                    ->whereColumn('lead_scores.lead_id', 'leads.id')
                    ->whereHas('icp', fn (Builder $icp): Builder => $icp->where('is_active', true))
                    ->limit(1),
            )
            ->orderBy('leads.id');
    }

    /**
     * How this lead may be approached, or null when it may not be approached at all.
     *
     * @return array{channel: OutreachChannel, restricted: bool}|null
     */
    private function approachFor(Team $team, Lead $lead): ?array
    {
        $open = array_filter(
            $this->guard->decideAll($team, $lead),
            static fn (OutreachDecision $decision): bool => ! $decision->isComplianceBlock(),
        );

        if ($open === []) {
            return null;
        }

        // Prefer a channel the platform can actually carry; fall back to the introduction-only
        // ones, which is the shape a UAE prior-consent lead comes in.
        foreach ([true, false] as $wantDirectMarketing) {
            foreach ($open as $decision) {
                if ($decision->channel->isDirectMarketing() === $wantDirectMarketing) {
                    return [
                        'channel' => $decision->channel,
                        'restricted' => ! $wantDirectMarketing,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{channel: OutreachChannel, restricted: bool}  $approach
     * @return array<string, mixed>
     */
    private function item(Lead $lead, array $approach): array
    {
        /** @var LeadScore|null $score */
        $score = $lead->activeScore;

        return [
            'id' => (int) $lead->getKey(),
            'company_name' => $lead->company_name,
            'grade' => $score?->grade->value,
            'score' => $score?->score,
            'channel' => $approach['channel']->value,
            // True when direct marketing is closed and only an introduction is open. The card says
            // so; it does not quietly suggest an email the guard would refuse.
            'restricted' => $approach['restricted'],
        ];
    }
}
