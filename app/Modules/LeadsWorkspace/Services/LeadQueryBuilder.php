<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Turns a {@see LeadFilters} into a query over a team's leads. The **one** place the leads list's
 * meaning lives, so the web screen ({@see ListLeadsAction}), `/api/v1`, a saved view, a bulk
 * selection and a CSV export can't disagree about what "grade A in the UK" means.
 *
 * Two things it guarantees:
 *
 * - **Nothing from the request reaches the builder as SQL.** Sorts are enum cases mapped to
 *   columns; `LIKE` terms have their wildcards escaped, so a search for `100%` is a search for
 *   `100%` rather than a scan matching everything.
 * - **No eager loads of its own.** The caller decides what it needs on each row, because a list
 *   row, a board card and an export row want different things.
 *
 * Expects to be called inside the team's context.
 */
final class LeadQueryBuilder
{
    /** The `LIKE ... ESCAPE` character — see {@see self::contains()} for why it isn't a backslash. */
    private const string LIKE_ESCAPE = '!';

    /**
     * The comparison appended to each searchable column: a bound pattern plus the escape clause.
     * A constant so the SQL the search builds stays a compile-time literal — the column names are
     * written out in full at each call site and nothing from the request is ever concatenated in.
     */
    private const string LIKE = " like ? escape '".self::LIKE_ESCAPE."'";

    /**
     * @return Builder<Lead>
     */
    public function filtered(Team $team, LeadFilters $filters): Builder
    {
        $query = Lead::query()->where('leads.team_id', $team->getKey());

        $this->applyFilters($query, $filters, $this->defaultStageId($team, $filters), $this->hidesWeakLeads($team, $filters));
        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * How many leads the **same filters** are hiding for being below the team's confidence
     * threshold (PL1) — the N in "Hidden: N low-confidence leads".
     *
     * Asked here rather than computed by the screen so the count means exactly what the toggle
     * would reveal: the same filters with `include_low_confidence` flipped, and nothing else. A
     * count taken over the whole team would promise leads a filtered list would not show.
     *
     * Returns 0 without a query when the caller is already showing them — there is nothing hidden
     * to count, and a list that is not hiding anything should not pay for the question.
     */
    public function suppressedCount(Team $team, LeadFilters $filters): int
    {
        if (! $this->hidesWeakLeads($team, $filters)) {
            return 0;
        }

        return $this->filtered($team, $filters->revealingLowConfidence())
            ->where('leads.suppressed_low_confidence', true)
            ->toBase()
            ->getCountForPagination();
    }

    /**
     * The team's default stage, but only when the caller is filtering by stage at all — the answer
     * is only needed to decide whether to widen the filter to the unplaced leads, so a list that
     * isn't stage-filtered pays nothing.
     *
     * Resolved **here** rather than by each caller on purpose. It is an invariant of the data
     * ("null stage means the default column"), not a preference of one screen, and the previous
     * shape — a flag the controller had to remember to set — meant the API, the CSV export and a
     * saved view all quietly disagreed with the web list about which leads were in the first
     * column.
     */
    private function defaultStageId(Team $team, LeadFilters $filters): ?int
    {
        if ($filters->stageIds === []) {
            return null;
        }

        $id = PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->where('is_default', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether this list is hiding low-confidence leads at all (PL1).
     *
     * Two conditions, and the second is the one that matters: the caller has not asked to see them,
     * **and** the team has the why-now feature. Decided here rather than in each screen so every
     * consumer of a filtered list — the web index, `/api/v1`, a saved view, a bulk selection, the
     * CSV export — agrees about which leads exist. A team whose flag is off must see exactly the
     * list it saw yesterday, and a suppression column filled in by a dark-launched calculation
     * quietly hiding a third of their leads is the way a dark launch stops being dark.
     */
    private function hidesWeakLeads(Team $team, LeadFilters $filters): bool
    {
        return ! $filters->includeLowConfidence && PrelaunchFeature::WhyNow->activeFor($team);
    }

    /**
     * @param  Builder<Lead>  $query
     */
    private function applyFilters(Builder $query, LeadFilters $filters, ?int $defaultStageId, bool $hideWeak): void
    {
        $query
            ->when($filters->search !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $group): Builder => $this->applySearch($group, (string) $filters->search),
            ))
            ->when($filters->sources !== [], fn (Builder $q): Builder => $q->whereIn('leads.source', $filters->sources))
            ->when(
                $filters->enrichmentStatuses !== [],
                fn (Builder $q): Builder => $q->whereIn('leads.enrichment_status', $filters->enrichmentStatuses),
            )
            // Grade filters the lead by the score it holds against the active ICP. A lead with no
            // score simply isn't in a graded list — it has no grade to be in one by.
            ->when($filters->grades !== [], fn (Builder $q): Builder => $q->whereHas(
                'activeScore',
                fn (Builder $score): Builder => $score->whereIn('grade', $filters->grades),
            ))
            ->when($filters->country !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $group): Builder => $this->applyCountry($group, (string) $filters->country),
            ))
            // A lead with no stage sits in the default column (see the `pipeline_stage_id`
            // migration), so filtering for the default stage has to include the unplaced leads too
            // — otherwise the board's biggest column would filter to nothing.
            ->when($filters->stageIds !== [], fn (Builder $q): Builder => $q->where(
                fn (Builder $group): Builder => $group
                    ->whereIn('leads.pipeline_stage_id', $filters->stageIds)
                    ->when(
                        $defaultStageId !== null && in_array($defaultStageId, $filters->stageIds, strict: true),
                        fn (Builder $g): Builder => $g->orWhereNull('leads.pipeline_stage_id'),
                    ),
            ))
            // Every tag asked for, not any of them: narrowing by two tags means both.
            ->when($filters->tagIds !== [], function (Builder $q) use ($filters): void {
                foreach ($filters->tagIds as $tagId) {
                    $q->whereHas('tags', fn (Builder $tag): Builder => $tag->where(
                        (new LeadTag)->getTable().'.id',
                        $tagId,
                    ));
                }
            })
            ->when($filters->assigneeIds !== [], fn (Builder $q): Builder => $q->whereHas(
                'assignments',
                fn (Builder $assignment): Builder => $assignment->whereIn('user_id', $filters->assigneeIds),
            ))
            ->when($filters->unassigned, fn (Builder $q): Builder => $q->whereDoesntHave('assignments'))
            // --- Why now (PL1) ---
            // A lead "from Companies House" here means one with an item of *evidence* from
            // Companies House, which is not the same as `leads.source` — an imported company whose
            // filing we later read is both, and the member asking this question wants it.
            ->when($filters->evidenceSources !== [], fn (Builder $q): Builder => $q->whereHas(
                'evidence',
                fn (Builder $evidence): Builder => $evidence->whereIn('lead_evidence.source', $filters->evidenceSources),
            ))
            ->when($filters->evidenceKinds !== [], fn (Builder $q): Builder => $q->whereHas(
                'evidence',
                fn (Builder $evidence): Builder => $evidence->whereIn('lead_evidence.kind', $filters->evidenceKinds),
            ))
            // The one filter that is on by default. A lead whose confidence has not been worked out
            // yet is **never** suppressed (the column is false while `confidence` is null), so a
            // fresh discovery run is never hidden behind "show the weak ones".
            ->when(
                $hideWeak,
                fn (Builder $q): Builder => $q->where('leads.suppressed_low_confidence', false),
            );
    }

    /**
     * Free-text search over the company's own identifiers plus its contacts. Deliberately a
     * contains-match rather than full-text: SQLite (local/CI) and MySQL (prod) disagree about
     * full-text, and a team's index is small enough that correctness beats cleverness here.
     * Revisit with a real search index when a team's lead count makes it hurt.
     *
     * Each group is parenthesised in the SQL itself. A raw clause is dropped in verbatim, so an
     * un-bracketed `or` would escape the clause it was meant to sit inside — in the sub-query
     * below, it would break the `leads.id = lead_contacts.lead_id` correlation and match one
     * contact against every lead in the team.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $like = $this->contains($term);

        $query->whereRaw(
            '(leads.company_name'.self::LIKE
            .' or leads.company_number'.self::LIKE
            .' or leads.website'.self::LIKE
            .' or leads.industry'.self::LIKE
            .' or leads.locality'.self::LIKE.')',
            array_fill(0, 5, $like),
        );

        return $query->orWhereHas(
            'contacts',
            fn (Builder $contacts) => $contacts->whereRaw(
                '(lead_contacts.name'.self::LIKE
                .' or lead_contacts.email'.self::LIKE
                .' or lead_contacts.title'.self::LIKE.')',
                array_fill(0, 3, $like),
            ),
        );
    }

    /**
     * A two-letter value is read as an ISO country code (what the providers store); anything longer
     * is a place the team typed, so it is matched against the free-text location fields too.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyCountry(Builder $query, string $country): Builder
    {
        if (Str::length($country) === 2) {
            return $query->where('leads.country', Str::upper($country));
        }

        return $query->whereRaw(
            '(leads.country'.self::LIKE
            .' or leads.region'.self::LIKE
            .' or leads.locality'.self::LIKE.')',
            array_fill(0, 3, $this->contains($country)),
        );
    }

    /**
     * @param  Builder<Lead>  $query
     */
    private function applySort(Builder $query, LeadFilters $filters): void
    {
        $direction = $filters->resolvedDirection()->value;

        // Every ordering ends on `id`, which breaks the tie: without it, rows sharing a sort value
        // can swap between pages and a paginated list silently repeats or skips leads. For the two
        // orderings that are a total order on their own — newest, and company name read
        // alphabetically — the tie-break follows the asked-for direction; for the others it is
        // simply a stable secondary key, so it stays fixed.
        match ($filters->sort) {
            LeadSort::Newest => $query->orderBy('leads.id', $direction),
            LeadSort::Company => $query->orderBy('leads.company_name', $direction)->orderBy('leads.id', $direction),
            // The lead's fit against the active ICP, as a correlated sub-query so an ungraded lead
            // sorts as null rather than being dropped from the list by a join.
            LeadSort::Fit => $query->orderBy($this->activeScoreSubQuery(), $direction)->orderByDesc('leads.id'),
            // How ready the lead is to be CONTACTED (Q4) - a different question from fit, which is
            // blind to missing data by design. Nulls are "not computed yet" rather than zero, and
            // MySQL and SQLite disagree about where they sort, so the order is stated explicitly
            // (the lesson `SignalSortOrder` already learned about `signals.heat`).
            LeadSort::Readiness => $query
                ->orderByRaw('case when leads.readiness is null then 1 else 0 end')
                ->orderBy('leads.readiness', $direction)
                ->orderByDesc('leads.id'),
            // "Recently updated" means recently re-sourced or re-enriched, which is the freshness a
            // member is actually asking about — not when someone last added a note.
            LeadSort::Freshness => $query
                ->orderByRaw('coalesce(leads.enriched_at, leads.collected_at, leads.created_at) '.$direction)
                ->orderByDesc('leads.id'),
            // The two PL1 orderings. Nulls last, stated explicitly for the same reason readiness
            // states it: null is "not judged yet" rather than zero, and MySQL and SQLite disagree
            // about where it sorts. A lead with no trigger event has no timing score at all, so
            // sorting by urgency puts the whole un-evented tail at the bottom, which is right.
            LeadSort::Timing => $query
                ->orderByRaw('case when leads.timing_score is null then 1 else 0 end')
                ->orderBy('leads.timing_score', $direction)
                ->orderByDesc('leads.id'),
            LeadSort::Confidence => $query
                ->orderByRaw('case when leads.confidence is null then 1 else 0 end')
                ->orderBy('leads.confidence', $direction)
                ->orderByDesc('leads.id'),
        };
    }

    /**
     * @return Builder<LeadScore>
     */
    private function activeScoreSubQuery(): Builder
    {
        return LeadScore::query()
            ->select('lead_scores.score')
            ->join('icps', 'icps.id', '=', 'lead_scores.icp_id')
            ->whereColumn('lead_scores.lead_id', 'leads.id')
            ->where('icps.is_active', true)
            ->limit(1);
    }

    /**
     * A contains-pattern for the given term, with the characters `LIKE` treats as wildcards escaped
     * so they are matched literally — a search for `100%` finds "100% Pure Ltd" rather than every
     * lead in the team.
     *
     * `!` is the escape character rather than the conventional backslash because the engines this
     * runs on (SQLite locally and in CI, MySQL in production) disagree about whether a backslash
     * inside a string literal is itself an escape. `!` needs no escaping anywhere.
     */
    private function contains(string $term): string
    {
        $escape = self::LIKE_ESCAPE;

        return '%'.str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $term,
        ).'%';
    }
}
