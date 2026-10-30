<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Builder;

/**
 * The A–D fit mix across the team's scored leads, against the **active** ICP only — mixing grades
 * from a retired profile with the current one would compare two different definitions of "good".
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
    public function handle(Team $team): array
    {
        $counts = $this->teamContext->run($team, function () use ($team): array {
            /** @var array<string, int> $counts */
            $counts = LeadScore::query()
                ->where('lead_scores.team_id', $team->getKey())
                ->where('excluded', false)
                ->whereHas('icp', fn (Builder $query): Builder => $query->where('is_active', true))
                ->selectRaw('grade, count(*) as aggregate')
                ->groupBy('grade')
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
