<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Builder;

/**
 * The prospecting funnel: sourced → qualified → contacted → replied.
 *
 * The last two steps come back **unavailable** rather than zero. Outreach (Phase 11) owns contact
 * attempts and replies, and until it exists there is nothing to count — a confident `0` would read
 * as "we contacted nobody and nobody replied", which is a different claim from "we are not
 * tracking this yet". The screen greys those steps and names the phase instead.
 */
final readonly class BuildLeadFunnelAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<FunnelStep>
     */
    public function handle(Team $team): array
    {
        return $this->teamContext->run($team, function () use ($team): array {
            $sourced = $this->leads($team)->count();
            $qualified = $this->leads($team)
                ->whereHas(
                    'activeScore',
                    fn (Builder $query): Builder => $query
                        ->where('excluded', false)
                        ->whereIn('grade', [ScoreGrade::A, ScoreGrade::B]),
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
                FunnelStep::pending(
                    key: 'contacted',
                    label: 'Contacted',
                    caption: 'Arrives with Outreach',
                ),
                FunnelStep::pending(
                    key: 'replied',
                    label: 'Replies',
                    caption: 'Arrives with Outreach',
                ),
            ];
        });
    }

    /**
     * @return Builder<Lead>
     */
    private function leads(Team $team): Builder
    {
        return Lead::query()->where('leads.team_id', $team->getKey());
    }
}
