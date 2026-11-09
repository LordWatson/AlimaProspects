<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Support\DateRange;

/**
 * Of the prospects found in this window, how many we can actually reach — `leads.readiness`
 * grouped into bands.
 *
 * The companion to the fit mix beside it, and the pair is the point: fit answers *should we want
 * them* and is deliberately blind to missing data, which is why an A-grade lead can sit on the list
 * with no website and no contact. Readiness answers *can we reach them*. A dashboard that showed
 * only the first makes a team's best-looking cohort indistinguishable from a workable one, which is
 * the exact gap `Enrichment\Services\LeadReadiness` was added to close — it just never reached a
 * chart.
 *
 * **Never a verdict about the prospect.** A low band is a gap in *our* data, so the labels name
 * what we hold rather than grading the company, and the bar is drawn in a neutral tone with no
 * status colour anywhere near it (the rule `LeadReadiness` and `ConfidencePill` both state).
 *
 * `null` is its own band, not a zero. "We have not assessed this yet" and "there is nothing to
 * reach them by" are different states, and collapsing them would make a team that has not run
 * enrichment look like a team whose prospects cannot be contacted.
 *
 * Scoped by the lead's own arrival, like the fit mix, so both bars describe the same cohort as the
 * funnel above them.
 */
final readonly class BuildReachabilityMixAction
{
    /**
     * The bands, widest-open first: a key, its label, its inclusive lower bound, and the hint that
     * tells a reader what the band actually means.
     *
     * Thresholds live here rather than in config: they are the words on a chart, and a deployment
     * that moved them would be relabelling the same number for no one's benefit. The weights that
     * *produce* the number are a team's to tune, in `config/enrichment.php`, which is the knob that
     * matters.
     *
     * @var list<array{key: string, label: string, floor: int, hint: string}>
     */
    private const BANDS = [
        [
            'key' => 'ready',
            // Not "ready to approach". Whether a prospect **may** be approached is
            // `Outreach\Services\OutreachGuard`'s answer and nothing else's — a UAE signal carries
            // a prior-consent restriction that no amount of contact data satisfies — so a band
            // about how much we hold must not read as a go-ahead to send.
            'label' => 'Plenty to go on',
            'floor' => 70,
            'hint' => 'A routable contact and enough about them to write something worth reading',
        ],
        [
            'key' => 'partial',
            'label' => 'Some of the way there',
            'floor' => 40,
            'hint' => 'Something to go on, but not yet a contact you could use today',
        ],
        [
            'key' => 'sparse',
            'label' => 'Little to go on',
            'floor' => 0,
            'hint' => 'We hold almost nothing yet — enriching these is the quickest win',
        ],
    ];

    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team, DateRange $range): array
    {
        [$counts, $unassessed] = $this->teamContext->run($team, function () use ($team, $range): array {
            $leads = fn () => $range->constrain(
                Lead::query()->where('leads.team_id', $team->getKey()),
                'leads.created_at',
            );

            // One grouped pass over the indexed `(team_id, readiness)` pair, bucketed in PHP rather
            // than by a `CASE` in SQL: the band edges are a product decision that belongs beside
            // their labels, and a dialect-free query is one less thing MySQL and SQLite can disagree
            // about (which they have, over raw clauses, before — see `SignalsMysqlDialectTest`).
            /** @var array<int, int> $byScore */
            $byScore = $leads()
                ->whereNotNull('readiness')
                ->selectRaw('readiness, count(*) as aggregate')
                ->groupBy('readiness')
                ->pluck('aggregate', 'readiness')
                ->mapWithKeys(static fn (mixed $count, mixed $score): array => [
                    (int) $score => (int) $count,
                ])
                ->all();

            return [$byScore, $leads()->whereNull('readiness')->count()];
        });

        $slices = [];

        foreach (self::BANDS as $index => $band) {
            // Each band runs from its own floor up to just below the one above it, so the three
            // together cover 0–100 exactly once however the edges are later moved.
            $ceiling = $index === 0 ? 100 : self::BANDS[$index - 1]['floor'] - 1;
            $total = 0;

            foreach ($counts as $score => $count) {
                if ($score >= $band['floor'] && $score <= $ceiling) {
                    $total += $count;
                }
            }

            $slices[] = new BreakdownSlice(
                key: $band['key'],
                label: $band['label'],
                value: $total,
                hint: $band['hint'],
            );
        }

        $slices[] = new BreakdownSlice(
            key: 'unassessed',
            label: 'Not assessed yet',
            value: $unassessed,
            hint: 'Nothing has looked at these — not a judgement about the company',
        );

        return $slices;
    }
}
