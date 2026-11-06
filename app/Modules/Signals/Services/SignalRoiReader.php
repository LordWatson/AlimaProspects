<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalDefinitionRoi;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use Illuminate\Support\Facades\Date;

/**
 * Each of a team's signal types, with what it found and what it cost (S15 task 2).
 *
 * **Two sources, joined in PHP rather than in SQL**, and that is deliberate. The funnel comes from
 * `signals` and the cost from `signal_definition_spend`, and the two are scoped by *different
 * clocks*: a signal belongs to the window it was **found** in, and a cost to the day it was
 * **incurred** on. They agree for every run that is not straddling midnight; joining them in one
 * query would force one clock onto both and make the figure wrong in a way nobody could see.
 *
 * Keyed by `event_key` on both sides, which is what lets a type a team deleted still show its
 * history (ADR 0018 §4) — and what makes the read work at all, since a deleted definition's id is
 * nulled on both tables.
 *
 * The funnel's definitions are **S12's**, restated nowhere: counted per cluster representative,
 * "kept" meaning not dismissed, "progressed" meaning off the default column of the team's own
 * board. Two screens disagreeing about what "converted" means is worse than either being
 * imperfect, so if these ever need to change they change in both.
 *
 * Bounds reach the query in **UTC**, which is this module's standing requirement for any windowed
 * figure: a non-UTC team's dashboard shifts silently otherwise.
 */
final class SignalRoiReader
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * Every type this team has signals or spending for, keyed by `event_key`.
     *
     * @return array<string, SignalDefinitionRoi>
     */
    public function forTeam(Team $team, ?int $days = null): array
    {
        $days = max(1, $days ?? (int) config('signals.roi.window_days', 60));
        $since = Date::now()->utc()->subDays($days);

        return $this->teamContext->run($team, function () use ($team, $days, $since): array {
            $funnel = $this->funnel($team, $since);
            $spend = $this->spend($team, $since);

            $roi = [];

            // The union of both sides, because either can exist without the other and both cases
            // are worth seeing: a type that cost money and found nothing is the one a team most
            // needs to be shown, and a type whose signals arrived before this window still has a
            // funnel worth reading.
            foreach (array_unique([...array_keys($funnel), ...array_keys($spend)]) as $eventKey) {
                $f = $funnel[$eventKey] ?? ['found' => 0, 'kept' => 0, 'converted' => 0, 'progressed' => 0];
                $s = $spend[$eventKey] ?? ['queries' => 0, 'search' => 0, 'ai' => 0];

                $roi[$eventKey] = new SignalDefinitionRoi(
                    eventKey: $eventKey,
                    days: $days,
                    found: $f['found'],
                    kept: $f['kept'],
                    converted: $f['converted'],
                    progressed: $f['progressed'],
                    searchCostMicros: $s['search'],
                    aiCostMicros: $s['ai'],
                    queries: $s['queries'],
                );
            }

            return $roi;
        });
    }

    /**
     * Found → kept → converted → progressed, per type.
     *
     * The same shape and the same joins `BuildSignalPerformanceAction` uses, for the reason in the
     * class note. Left joins on both the lead and its stage, so a deleted lead
     * (`signals.lead_id` is `nullOnDelete`) cannot drop a type out of its own figures.
     *
     * @return array<string, array{found: int, kept: int, converted: int, progressed: int}>
     */
    private function funnel(Team $team, \DateTimeInterface $since): array
    {
        $rows = Signal::query()
            ->where('signals.team_id', $team->getKey())
            ->representatives()
            ->where('signals.created_at', '>=', $since)
            ->leftJoin('leads', 'leads.id', '=', 'signals.lead_id')
            ->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'leads.pipeline_stage_id')
            ->groupBy('signals.event_key')
            ->selectRaw(
                'signals.event_key as event_key, '
                .'count(*) as found, '
                .'sum(case when signals.status != ? then 1 else 0 end) as kept, '
                .'sum(case when signals.status = ? then 1 else 0 end) as converted, '
                .'sum(case when leads.id is not null and pipeline_stages.is_default = 0 then 1 else 0 end) as progressed',
                [SignalStatus::Dismissed->value, SignalStatus::Converted->value],
            )
            ->toBase()
            ->get();

        $funnel = [];

        foreach ($rows as $row) {
            $funnel[(string) $row->event_key] = [
                'found' => (int) $row->found,
                'kept' => (int) $row->kept,
                'converted' => (int) $row->converted,
                'progressed' => (int) $row->progressed,
            ];
        }

        return $funnel;
    }

    /**
     * Queries and cost, per type, from the ledger.
     *
     * @return array<string, array{queries: int, search: int, ai: int}>
     */
    private function spend(Team $team, \DateTimeInterface $since): array
    {
        $rows = SignalDefinitionSpend::query()
            ->where('team_id', $team->getKey())
            // The ledger's `day` is a date, so the window is compared as one. Taking the date of a
            // UTC instant rather than the instant itself, because a `>=` against a timestamp would
            // silently drop the first day's rows on every driver that stores a date as midnight.
            ->where('day', '>=', Date::instance($since)->utc()->toDateString())
            ->groupBy('event_key')
            ->selectRaw(
                'event_key, '
                .'sum(queries) as queries, '
                .'sum(search_cost_micros) as search_cost, '
                .'sum(ai_cost_micros) as ai_cost',
            )
            ->toBase()
            ->get();

        $spend = [];

        foreach ($rows as $row) {
            $spend[(string) $row->event_key] = [
                'queries' => (int) $row->queries,
                'search' => (int) $row->search_cost,
                'ai' => (int) $row->ai_cost,
            ];
        }

        return $spend;
    }
}
