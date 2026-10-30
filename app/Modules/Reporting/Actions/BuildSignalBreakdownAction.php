<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;

/**
 * The kinds of trigger event the team's watches are finding, busiest first — counted over
 * **undismissed** signals only, because a dismissed one is a judgement that it was not an event
 * worth acting on, and leaving it in would make a noisy watch look productive.
 *
 * Event types with nothing found are dropped (same reasoning as the source breakdown: the type
 * list is Alima's, not the team's).
 */
final readonly class BuildSignalBreakdownAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team): array
    {
        $counts = $this->teamContext->run($team, function () use ($team): array {
            /** @var array<string, int> $counts */
            $counts = Signal::query()
                ->where('signals.team_id', $team->getKey())
                ->whereIn('status', [SignalStatus::New, SignalStatus::Converted])
                ->selectRaw('event_type, count(*) as aggregate')
                ->groupBy('event_type')
                ->pluck('aggregate', 'event_type')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            return $counts;
        });

        $slices = [];

        foreach (SignalEventType::cases() as $type) {
            $value = $counts[$type->value] ?? 0;

            if ($value === 0) {
                continue;
            }

            $slices[] = new BreakdownSlice(
                key: $type->value,
                label: $type->label(),
                value: $value,
            );
        }

        usort($slices, static fn (BreakdownSlice $a, BreakdownSlice $b): int => $b->value <=> $a->value);

        return $slices;
    }
}
