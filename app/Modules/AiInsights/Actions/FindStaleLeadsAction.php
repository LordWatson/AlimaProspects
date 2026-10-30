<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Actions;

use App\Modules\AiInsights\Data\InsightDraft;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\Scoring\Models\LeadScore;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stale-lead alert (Phase 14): companies the team **picked up** and then went quiet on.
 *
 * ### What counts as stale, and why it is not "untouched"
 *
 * The obvious query — a lead with no recent activity — makes the one insight nobody can act on: a
 * team that sourced four hundred companies on Monday has four hundred "stale leads" a fortnight
 * later, which is not a problem with their follow-up, it is a backlog. So a lead only goes stale
 * once the team has **started** it: approached it, been assigned it, or moved it off the default
 * column. Before that it is unworked, which is a different sentence and a different fix.
 *
 * Leads in a Won or Lost stage are excluded — silence is the correct state for a closed lead, and
 * an alert about one is the fastest way to teach a team to ignore the feed. A lead with **no**
 * stage is in the team's default column (see the `pipeline_stage_id` migration), which is never
 * terminal, so it stays in scope.
 *
 * "Last touched" is the lead's own timeline (`lead_activities`), which every meaningful event
 * writes to — including an approach (`LeadActivityType::Contacted`) — falling back to when the
 * lead was created. One source rather than three, so the rule cannot disagree with itself.
 */
final readonly class FindStaleLeadsAction
{
    /**
     * The lead's last timeline entry, or its creation. A constant so `selectRaw`/`orderByRaw` get a
     * `literal-string`: no part of a column list is ever assembled from data.
     *
     * @var literal-string
     */
    private const string LAST_TOUCH = '(select coalesce(max(la.occurred_at), leads.created_at) '
        // `team_id` as well as `lead_id`, which is not redundant: `lead_activities` is indexed on
        // (team_id, lead_id, occurred_at), and a sub-select filtering on `lead_id` alone cannot use
        // that index as a prefix — it falls back to the foreign key. Matching the composite index
        // is the difference between an index seek and a scan per lead on a long-lived team.
        .'from lead_activities la where la.lead_id = leads.id and la.team_id = leads.team_id)';

    public function __construct(private TeamContext $teamContext) {}

    /**
     * @param  string  $dateKey  The team's local `Y-m-d`, which the dedupe key is built from so two
     *                           runs on the same day produce one insight.
     */
    public function handle(Team $team, string $dateKey, ?DateTimeInterface $now = null): ?InsightDraft
    {
        $staleAfterDays = max(1, (int) config('ai-insights.stale.after_days', 14));
        $maxItems = max(1, (int) config('ai-insights.stale.max_items', 5));

        // UTC before anything reaches the query. `now()` already is, but the conversion is written
        // out because a caller may pass a team-local instant, and Laravel binds a
        // `DateTimeInterface` by formatting it with no conversion at all — the bug that shifts
        // every figure on a Dubai team's dashboard (see Reporting's `DateRange::constrain`).
        $asOf = CarbonImmutable::parse($now ?? CarbonImmutable::now())->utc();
        $cutoff = $asOf->subDays($staleAfterDays);

        [$total, $leads] = $this->teamContext->run($team, function () use ($team, $cutoff, $maxItems): array {
            $query = $this->candidates($team, $cutoff);

            return [
                // Counted separately from the list: the alert says how many have gone quiet and
                // names a handful. A member with sixty needs the number, not sixty cards.
                (clone $query)->toBase()->count(),
                $query
                    ->select('leads.*')
                    ->selectRaw(self::LAST_TOUCH.' as last_touch_at')
                    ->with('activeScore')
                    // The most neglected first. Ordering by fit instead would bury a company the
                    // team has been sitting on for a month behind a fresher A grade.
                    ->orderByRaw(self::LAST_TOUCH.' asc')
                    ->orderBy('leads.id')
                    ->limit($maxItems)
                    ->get(),
            ];
        });

        if ($total === 0) {
            return null;
        }

        return new InsightDraft(
            type: InsightType::StaleLeads,
            severity: InsightType::StaleLeads->defaultSeverity(),
            payload: [
                'count' => $total,
                'days' => $staleAfterDays,
                'leads' => $leads->map(fn (Lead $lead): array => $this->item($lead, $asOf))->all(),
            ],
            dedupeKey: InsightType::StaleLeads->value.':'.$dateKey,
            periodStart: $cutoff,
            periodEnd: $asOf,
        );
    }

    /**
     * Started, not closed, and quiet since the cutoff.
     *
     * @return Builder<Lead>
     */
    private function candidates(Team $team, CarbonImmutable $cutoff): Builder
    {
        return Lead::query()
            // Scoped twice: inside the team context's global scope and explicitly (ADR 0006).
            ->where('leads.team_id', $team->getKey())
            ->whereDoesntHave(
                'pipelineStage',
                fn (Builder $stage): Builder => $stage->whereIn('outcome', [
                    StageOutcome::Won->value,
                    StageOutcome::Lost->value,
                ]),
            )
            ->where(fn (Builder $started): Builder => $started
                ->whereHas('contactAttempts')
                ->orWhereHas('assignments')
                ->orWhereHas('pipelineStage', fn (Builder $stage): QueryBuilder => $stage->where('is_default', false)))
            ->whereRaw(self::LAST_TOUCH.' < ?', [$cutoff]);
    }

    /**
     * One named company in the payload: the id and the facts the card shows, and **nothing about a
     * person** — an insight points at a lead and the lead owns the contact data.
     *
     * @return array<string, mixed>
     */
    private function item(Lead $lead, CarbonImmutable $asOf): array
    {
        /** @var LeadScore|null $score */
        $score = $lead->activeScore;
        $lastTouch = $lead->getAttribute('last_touch_at');

        return [
            'id' => (int) $lead->getKey(),
            'company_name' => $lead->company_name,
            'grade' => $score?->grade->value,
            'score' => $score?->score,
            // Read off the aliased column the list query selected, so the number on the card and
            // the predicate that chose the row are the same expression.
            'days_quiet' => is_string($lastTouch) || $lastTouch instanceof DateTimeInterface
                ? (int) CarbonImmutable::parse($lastTouch)->utc()->diffInDays($asOf)
                : null,
        ];
    }
}
