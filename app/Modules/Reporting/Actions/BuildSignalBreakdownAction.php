<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Support\Str;

/**
 * The kinds of trigger event the team's watches are finding, busiest first — counted over
 * **undismissed** signals only, because a dismissed one is a judgement that it was not an event
 * worth acting on, and leaving it in would make a noisy watch look productive.
 *
 * Scoped by when the signal was **found**, not by a lead cohort: a signal is an event in the
 * world, and plenty of them never become a lead at all.
 *
 * **Since S7 the slices are the team's own signal types** (ADR 0018), where they used to be the
 * three `SignalEventType` cases. Two consequences worth stating, because they changed what this
 * action can and cannot do:
 *
 * - It groups by the stored `event_key` and labels from the team's definitions, rather than
 *   iterating a fixed set of cases. A type with nothing found in the window is still dropped — the
 *   same reasoning as before, now with a better justification: the list is the team's, and showing
 *   them a zero against a type they wrote is noise, not information.
 * - **A key with no definition still gets a slice**, labelled from the key itself. That is the case
 *   a fixed enum never had: a team can delete a signal type, and the signals it found are history
 *   that still has to add up. A breakdown whose slices summed to less than the total would be a
 *   report nobody could trust.
 */
final readonly class BuildSignalBreakdownAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team, DateRange $range): array
    {
        /** @var array{counts: array<string, int>, labels: array<string, string>} $data */
        $data = $this->teamContext->run($team, function () use ($team, $range): array {
            /** @var array<string, int> $counts */
            // **Events, not articles** (Signals S11), the same predicate the feed counts with:
            // a breakdown where one well-covered acquisition weighs eight times a thinly-covered
            // one is a chart about press attention rather than about the team's trigger events.
            $counts = $range->constrain(
                Signal::query()
                    ->where('signals.team_id', $team->getKey())
                    ->representatives()
                    ->whereIn('status', [SignalStatus::New, SignalStatus::Converted]),
                'signals.created_at',
            )
                ->selectRaw('event_key, count(*) as aggregate')
                ->groupBy('event_key')
                ->pluck('aggregate', 'event_key')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            // One query for the labels, keyed by the same column the counts are grouped by. Only
            // the keys that actually appeared, so a team with a large library pays for the types
            // that produced something rather than all of them.
            /** @var array<string, string> $labels */
            $labels = $counts === [] ? [] : SignalDefinition::query()
                // `withTrashed`, for the same reason the signal's own relation uses it: a type the
                // team removed still named the signals it found, and a report row reading
                // "Hiring Surge Uae" where it used to read the team's own words is a regression
                // nobody would attribute to the delete.
                ->withTrashed()
                ->where('team_id', $team->getKey())
                ->whereIn('key', array_keys($counts))
                ->pluck('name', 'key')
                ->all();

            return ['counts' => $counts, 'labels' => $labels];
        });

        $slices = [];

        foreach ($data['counts'] as $key => $value) {
            if ($value === 0) {
                continue;
            }

            $slices[] = new BreakdownSlice(
                key: $key,
                // `Str::headline` for a key whose definition has been deleted: "funding_round"
                // becomes "Funding Round", which is a fair label and plainly better than a slug.
                label: $data['labels'][$key] ?? Str::headline($key),
                value: $value,
            );
        }

        usort($slices, static fn (BreakdownSlice $a, BreakdownSlice $b): int => $b->value <=> $a->value);

        return $slices;
    }
}
