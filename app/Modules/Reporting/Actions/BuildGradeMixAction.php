<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Builder;

/**
 * The A–D fit mix across the team's scored leads, against the **active** ICP only — mixing grades
 * from a retired profile with the current one would compare two different definitions of "good".
 *
 * Scoped by the lead's own arrival, not by when it was scored, so the mix explains the same cohort
 * as the funnel above it: "of the prospects we found in this period, how they graded". A mix keyed
 * off scoring dates would describe a different set of companies from every other chart on the
 * screen.
 *
 * All four letters always come back, zeros included: a mix with no D is a fact worth showing, and
 * a missing segment would silently change what the bar is a proportion of.
 */
final readonly class BuildGradeMixAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team, DateRange $range): array
    {
        $counts = $this->teamContext->run($team, function () use ($team, $range): array {
            /** @var array<string, int> $counts */
            $counts = $range->constrain(
                LeadScore::query()
                    ->join('leads', 'leads.id', '=', 'lead_scores.lead_id')
                    ->where('lead_scores.team_id', $team->getKey())
                    // The joined table is pinned too. The global scope only constrains
                    // `lead_scores`, so this is the row that stops a stray cross-team
                    // `lead_id` pulling another team's lead into the cohort — belt as well as
                    // braces, since today it is foreign-key integrity alone saying it cannot.
                    ->where('leads.team_id', $team->getKey())
                    ->where('lead_scores.excluded', false)
                    ->whereHas('icp', fn (Builder $query): Builder => $query->where('is_active', true)),
                'leads.created_at',
            )
                // Qualified: `leads` has no `grade` today, and an unqualified column is one
                // migration away from an ambiguous-column 500 in production only.
                ->selectRaw('lead_scores.grade as grade, count(*) as aggregate')
                ->groupBy('lead_scores.grade')
                ->pluck('aggregate', 'grade')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            return $counts;
        });

        return array_map(
            static fn (ScoreGrade $grade): BreakdownSlice => new BreakdownSlice(
                key: $grade->value,
                label: $grade->label(),
                value: $counts[$grade->value] ?? 0,
                hint: $grade->description(),
            ),
            ScoreGrade::cases(),
        );
    }
}
