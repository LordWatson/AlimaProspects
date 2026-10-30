<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Builder;

/**
 * The prospecting funnel: sourced → qualified → contacted → replied.
 *
 * Every step counts **leads**, from the same cohort — those sourced inside the window (see
 * {@see DateRange}). So "contacted" is *of the prospects we found in this period, how many have we
 * approached*, not *how many approaches we made*: one lead emailed three times is one lead, which
 * is the only reading under which a funnel narrows. How many approaches were made, and how they
 * went, is the outreach performance report's question instead.
 *
 * ## It is not a strict ladder, and must not be read as one
 *
 * `sourced ≥` every other step, and `contacted ≥ replied`. But **"strong fits" and "contacted" are
 * parallel cuts of the cohort, not consecutive stages**: a team is free to call a C-grade company
 * it liked the look of, and nothing stops it skipping an A. So contacted can exceed strong fits and
 * the chart can widen in the middle.
 *
 * That is a fact about how the team worked, not a bug to normalise away. Restricting contacted to
 * the strong-fit subset would make the picture tidier and the number wrong — it would stop counting
 * real approaches to real companies — and a team whose middle bar bulges is being told something
 * worth knowing: it is calling prospects its ICP does not rate.
 *
 * The last two steps were unavailable until Phase 11 built Outreach. They are real counts now —
 * and a `0` here means zero, never "not tracked", because {@see FunnelStep::pending()} is still
 * what the screen draws an em dash for.
 */
final readonly class BuildLeadFunnelAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<FunnelStep>
     */
    public function handle(Team $team, DateRange $range): array
    {
        return $this->teamContext->run($team, function () use ($team, $range): array {
            $sourced = $this->leads($team, $range)->count();

            $qualified = $this->leads($team, $range)
                ->whereHas(
                    'activeScore',
                    fn (Builder $query): Builder => $query
                        ->where('excluded', false)
                        ->whereIn('grade', [ScoreGrade::A, ScoreGrade::B]),
                )
                ->count();

            $contacted = $this->leads($team, $range)
                ->whereHas('contactAttempts')
                ->count();

            $replied = $this->leads($team, $range)
                ->whereHas(
                    'contactAttempts',
                    fn (Builder $query): Builder => $query->whereIn('outcome', $this->responses()),
                )
                ->count();

            return [
                new FunnelStep(
                    key: 'sourced',
                    label: 'Prospects sourced',
                    caption: 'From Companies House, the web and Google Places',
                    value: $sourced,
                ),
                new FunnelStep(
                    key: 'qualified',
                    label: 'Strong fits',
                    caption: 'Graded A or B against your active ideal customer',
                    value: $qualified,
                ),
                new FunnelStep(
                    key: 'contacted',
                    label: 'Contacted',
                    caption: 'Prospects somebody has approached at least once',
                    value: $contacted,
                ),
                new FunnelStep(
                    key: 'replied',
                    label: 'Replies',
                    caption: 'Prospects who came back to us, however they answered',
                    value: $replied,
                ),
            ];
        });
    }

    /**
     * The cohort: leads sourced inside the window.
     *
     * @return Builder<Lead>
     */
    private function leads(Team $team, DateRange $range): Builder
    {
        return $range->constrain(
            Lead::query()->where('leads.team_id', $team->getKey()),
            'leads.created_at',
        );
    }

    /**
     * @return list<string>
     */
    private function responses(): array
    {
        return array_values(array_map(
            static fn (ContactOutcome $outcome): string => $outcome->value,
            array_filter(
                ContactOutcome::cases(),
                static fn (ContactOutcome $outcome): bool => $outcome->isResponse(),
            ),
        ));
    }
}
