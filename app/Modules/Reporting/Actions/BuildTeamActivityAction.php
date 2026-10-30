<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What each member actually did in the window (Phase 13).
 *
 * Read from the **lead timeline** rather than from six separate tables. The timeline already
 * records one row per thing that happened to a lead, with who did it and when it happened, so this
 * report is two queries over one table instead of a join across notes, tasks, assignments and
 * contact attempts — and it stays correct when a later phase adds a new kind of action, because
 * the "actions" total counts rows rather than a hard-coded list.
 *
 * Scoped by `occurred_at`: when the member did the thing, not when the row was written (a queued
 * listener can record work that finished earlier).
 *
 * Rows with **no user** are Alima's own work — a queued enrichment, an automatic score — and are
 * left out. Attributing a discovery run to nobody in a table titled "team activity" would read as
 * a gap in the data rather than as the platform doing its job.
 *
 * Every current member gets a row, including one who did nothing. A member at zero for the month
 * is the single most actionable line in the table; dropping them would hide exactly the thing a
 * manager opened it for.
 */
final readonly class BuildTeamActivityAction
{
    /**
     * The columns that break the total down, and the activity type behind each. Chosen because
     * each is a thing a person *chose* to do — the deliberate acts a manager is looking for.
     *
     * @var array<string, LeadActivityType>
     */
    private const BREAKDOWN = [
        'contacted' => LeadActivityType::Contacted,
        'notes' => LeadActivityType::NoteAdded,
        'stage_moves' => LeadActivityType::StageChanged,
        'tasks_done' => LeadActivityType::TaskCompleted,
    ];

    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, DateRange $range): ReportTable
    {
        [$totals, $byType, $members] = $this->teamContext->run(
            $team,
            fn (): array => [
                $this->totals($team, $range),
                $this->byType($team, $range),
                $this->members($team),
            ],
        );

        $rows = [];

        foreach ($members as $member) {
            $id = (int) $member->getKey();
            $total = $totals->get($id);

            $cells = [
                'member' => (string) $member->name,
                'role' => $this->role($member)->label(),
                'actions' => (int) ($total->actions ?? 0),
                'leads' => (int) ($total->leads ?? 0),
            ];

            foreach (self::BREAKDOWN as $column => $type) {
                $cells[$column] = (int) ($byType[$id][$type->value] ?? 0);
            }

            // ISO-8601 so the client can render it in the team's timezone; null when the member did
            // nothing in the window, which the table shows as an em dash rather than a date.
            $cells['last_active'] = $total === null || $total->last_active === null
                ? null
                : $this->iso((string) $total->last_active);

            $rows[] = new ReportRow(key: 'member-'.$id, cells: $cells);
        }

        // Busiest first, and a member with nothing to show sinks to the bottom in name order.
        usort($rows, static function (ReportRow $a, ReportRow $b): int {
            return [(int) $b->cells['actions'], (string) $a->cells['member']]
                <=> [(int) $a->cells['actions'], (string) $b->cells['member']];
        });

        return ReportTable::of(
            ReportKey::TeamActivity,
            $this->columns(),
            $rows,
            'Nobody has worked a lead in this period.',
        );
    }

    /**
     * One row per member: how many things they did, over how many companies, and when they last
     * did one.
     *
     * @return Collection<int, object{
     *     user_id: int|string,
     *     actions: int|string,
     *     leads: int|string,
     *     last_active: string|null,
     * }>
     */
    private function totals(Team $team, DateRange $range): Collection
    {
        /**
         * @var Collection<int, object{
         *     user_id: int|string,
         *     actions: int|string,
         *     leads: int|string,
         *     last_active: string|null,
         * }> $totals
         */
        $totals = $this->activities($team, $range)
            ->groupBy('user_id')
            ->selectRaw(
                'user_id, count(*) as actions, count(distinct lead_id) as leads, '
                .'max(occurred_at) as last_active',
            )
            ->toBase()
            ->get()
            ->keyBy('user_id');

        return $totals;
    }

    /**
     * The per-type counts, as `[user_id => [type => count]]`. A second query rather than more
     * conditional columns on the first, because it is the one that has to stay cheap as the type
     * list grows.
     *
     * @return array<int, array<string, int>>
     */
    private function byType(Team $team, DateRange $range): array
    {
        $counts = [];

        $rows = $this->activities($team, $range)
            ->whereIn('type', array_map(
                static fn (LeadActivityType $type): string => $type->value,
                array_values(self::BREAKDOWN),
            ))
            ->groupBy('user_id', 'type')
            ->selectRaw('user_id, type, count(*) as aggregate')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->user_id][(string) $row->type] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return Builder<LeadActivity>
     */
    private function activities(Team $team, DateRange $range): Builder
    {
        return $range->constrain(
            LeadActivity::query()
                ->where('lead_activities.team_id', $team->getKey())
                ->whereNotNull('user_id'),
            'lead_activities.occurred_at',
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function members(Team $team): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $team->users()->orderBy('name')->get();

        return $members;
    }

    /**
     * The member's role on this team. Unknown pivot values fall back to Read-only — the least
     * privileged reading, so a bad row never overstates what somebody is.
     */
    private function role(User $member): TeamRole
    {
        $pivot = $member->getRelation('pivot');

        return TeamRole::tryFrom(
            $pivot instanceof Pivot ? (string) $pivot->getAttribute('role') : '',
        ) ?? TeamRole::ReadOnly;
    }

    /**
     * A database timestamp string as ISO-8601 UTC. SQLite hands back `Y-m-d H:i:s` and MySQL the
     * same, and neither is a format the client's `Date` constructor reads the same way in every
     * browser — so it is normalised here rather than hoped about there.
     */
    private function iso(string $timestamp): ?string
    {
        try {
            return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
                ->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(): array
    {
        return [
            new ReportColumn('member', 'Member'),
            ReportColumn::translated('role', 'Role'),
            ReportColumn::count('actions', 'Actions'),
            ReportColumn::count('leads', 'Companies worked'),
            ReportColumn::count('contacted', 'Approaches'),
            ReportColumn::count('notes', 'Notes'),
            ReportColumn::count('stage_moves', 'Stage moves'),
            ReportColumn::count('tasks_done', 'Tasks done'),
            ReportColumn::date('last_active', 'Last active'),
        ];
    }
}
