<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Support\DateRange;

/**
 * Where the team's leads came from, busiest source first — over the cohort sourced in the selected
 * window.
 *
 * Unlike the grade mix, a source with nothing in it is dropped: the list of providers is Alima's,
 * not the team's, and a UK-only team has no reason to see "Google Places — 0" every day. The
 * discovery screen is where a team picks sources; this chart reports on the ones they used.
 */
final readonly class BuildSourceBreakdownAction
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
                Lead::query()->where('leads.team_id', $team->getKey()),
                'leads.created_at',
            )
                ->selectRaw('source, count(*) as aggregate')
                ->groupBy('source')
                ->pluck('aggregate', 'source')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            return $counts;
        });

        $slices = [];

        foreach (LeadSource::cases() as $source) {
            $value = $counts[$source->value] ?? 0;

            if ($value === 0) {
                continue;
            }

            $slices[] = new BreakdownSlice(
                key: $source->value,
                label: $source->label(),
                value: $value,
            );
        }

        usort($slices, static fn (BreakdownSlice $a, BreakdownSlice $b): int => $b->value <=> $a->value);

        return $slices;
    }
}
