<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use Illuminate\Support\Collection;

/**
 * How the team's approaches went, by channel (Phase 13) — email, LinkedIn, call side by side.
 *
 * Scoped by **when the approach happened** (`occurred_at`), not by the lead cohort the rest of the
 * dashboard uses, and not by when the row was written: this report is about the team's activity in
 * the window, and a member logging yesterday's call today did not make the call today. The funnel
 * above answers the cohort question instead.
 *
 * It counts **attempts**, so a lead emailed three times is three rows here and one lead in the
 * funnel. Those are different questions and the captions say which is which.
 *
 * Channels with no attempts are dropped: the channel list is Alima's, and a team that only emails
 * has no use for "Call — 0" every day.
 */
final readonly class BuildOutreachPerformanceAction
{
    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, DateRange $range): ReportTable
    {
        $rows = $this->teamContext->run($team, function () use ($team, $range): Collection {
            /**
             * @var Collection<string, object{
             *     channel: string,
             *     attempts: int|string,
             *     companies: int|string,
             *     responses: int|string,
             *     positives: int|string,
             *     waiting: int|string,
             *     opt_outs: int|string,
             * }> $aggregates
             */
            $aggregates = $range->constrain(
                ContactAttempt::query()->where('contact_attempts.team_id', $team->getKey()),
                'contact_attempts.occurred_at',
            )
                ->groupBy('channel')
                ->selectRaw(
                    'channel, count(*) as attempts, '
                    .'count(distinct lead_id) as companies, '
                    .$this->outcomeSum('responses', $this->responses()).', '
                    .$this->outcomeSum('positives', $this->positives()).', '
                    .$this->outcomeSum('waiting', [ContactOutcome::Pending]).', '
                    .$this->outcomeSum('opt_outs', [ContactOutcome::OptedOut]),
                    [
                        ...$this->values($this->responses()),
                        ...$this->values($this->positives()),
                        ContactOutcome::Pending->value,
                        ContactOutcome::OptedOut->value,
                    ],
                )
                ->toBase()
                ->get()
                ->keyBy('channel');

            return $aggregates;
        });

        $reportRows = [];

        // Driven off the enum rather than the result set, so the channels keep a stable order
        // instead of shuffling with the data (colour and order follow the entity, never its rank).
        foreach (OutreachChannel::cases() as $channel) {
            $row = $rows->get($channel->value);

            if ($row === null) {
                continue;
            }

            $attempts = (int) $row->attempts;
            $responses = (int) $row->responses;

            $reportRows[] = new ReportRow(
                key: $channel->value,
                cells: [
                    'channel' => $channel->label(),
                    'attempts' => $attempts,
                    'companies' => (int) $row->companies,
                    'responses' => $responses,
                    'reply_rate' => Ratio::of($responses, $attempts),
                    'positives' => (int) $row->positives,
                    'waiting' => (int) $row->waiting,
                    'opt_outs' => (int) $row->opt_outs,
                ],
            );
        }

        return ReportTable::of(
            ReportKey::Outreach,
            $this->columns(),
            $reportRows,
            'Nobody has been approached in this period.',
        );
    }

    /**
     * A conditional-aggregation column counting the attempts whose outcome is one of `$outcomes`.
     *
     * The alias is a literal and the outcome values are **bound**, not interpolated: `selectRaw`
     * only accepts a `literal-string` precisely so that no part of a column list can ever be
     * assembled from data.
     *
     * @param  literal-string  $alias
     * @param  list<ContactOutcome>  $outcomes
     * @return literal-string
     */
    private function outcomeSum(string $alias, array $outcomes): string
    {
        $placeholders = implode(', ', array_fill(0, max(1, count($outcomes)), '?'));

        return 'sum(case when outcome in ('.$placeholders.') then 1 else 0 end) as '.$alias;
    }

    /**
     * @param  list<ContactOutcome>  $outcomes
     * @return list<string>
     */
    private function values(array $outcomes): array
    {
        return array_map(static fn (ContactOutcome $outcome): string => $outcome->value, $outcomes);
    }

    /**
     * @return list<ContactOutcome>
     */
    private function responses(): array
    {
        return array_values(array_filter(
            ContactOutcome::cases(),
            static fn (ContactOutcome $outcome): bool => $outcome->isResponse(),
        ));
    }

    /**
     * @return list<ContactOutcome>
     */
    private function positives(): array
    {
        return array_values(array_filter(
            ContactOutcome::cases(),
            static fn (ContactOutcome $outcome): bool => $outcome->isPositive(),
        ));
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(): array
    {
        return [
            ReportColumn::translated('channel', 'Channel'),
            ReportColumn::count('attempts', 'Approaches'),
            ReportColumn::count('companies', 'Companies'),
            ReportColumn::count('responses', 'Replies'),
            ReportColumn::percent('reply_rate', 'Reply rate'),
            ReportColumn::count('positives', 'Interested or meeting'),
            ReportColumn::count('waiting', 'Still waiting'),
            ReportColumn::count('opt_outs', 'Asked us to stop'),
        ];
    }
}
