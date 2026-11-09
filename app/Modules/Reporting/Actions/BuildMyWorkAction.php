<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Reporting\Data\MyTask;
use App\Modules\Reporting\Data\MyWork;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * What is waiting on **one member**, and what they got through in the window.
 *
 * The rest of the dashboard answers "how is the team doing". This answers "what should I do next",
 * which is a different question and the one a member actually opens a dashboard with — on a
 * ten-person team there is otherwise no way to find your own four companies in a list of four
 * hundred.
 *
 * The queues are **not** window-scoped and the activity figures are; {@see MyWork} says why, and
 * the panel says it on screen so a reader is never guessing which rule a number followed.
 *
 * Ownership is `lead_assignments`, the same relation the leads list filters by and the same one
 * Signals addresses an account notification to — not `leads.created_by`, which records who ran the
 * discovery rather than who is working the company.
 */
final readonly class BuildMyWorkAction
{
    /** How many of the next tasks the panel lists. A short list somebody will actually read. */
    private const TASK_PREVIEW = 5;

    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, User $member, DateRange $range): MyWork
    {
        return $this->teamContext->run($team, function () use ($team, $member, $range): MyWork {
            $id = (int) $member->getKey();
            $now = Date::now($range->timezone)->toImmutable();

            $activity = $range->constrain(
                LeadActivity::query()
                    ->where('lead_activities.team_id', $team->getKey())
                    ->where('lead_activities.user_id', $id),
                'lead_activities.occurred_at',
            );

            // One pass over the timeline for all three activity figures — the same table, the same
            // `occurred_at` scoping and the same definition of an "action" as the team activity
            // table, so a member's row there and their panel here can never disagree.
            /** @var object{actions: int|string, leads: int|string, approaches: int|string}|null $totals */
            $totals = $activity->clone()
                ->selectRaw(
                    'count(*) as actions, count(distinct lead_id) as leads, '
                    .'sum(case when type = ? then 1 else 0 end) as approaches',
                    [LeadActivityType::Contacted->value],
                )
                ->toBase()
                ->first();

            $tasks = $this->openTasks($team, $id);

            return new MyWork(
                member: (string) $member->name,
                open_leads: $this->openLeads($team, $id)->count(),
                tasks_overdue: $tasks->clone()->where('due_at', '<', $now->utc())->count(),
                tasks_due_today: $tasks->clone()
                    ->whereBetween('due_at', [$now->utc(), $now->endOfDay()->utc()])
                    ->count(),
                signals_waiting: $this->waitingSignals($team, $id),
                actions: (int) ($totals->actions ?? 0),
                companies_worked: (int) ($totals->leads ?? 0),
                approaches: (int) ($totals->approaches ?? 0),
                // The same instant the counts were taken against, passed in rather than read
                // again: two clocks a few milliseconds apart can disagree about whether the
                // task on the boundary is overdue, and a card that counts one overdue and
                // then lists it as due is a card nobody trusts twice.
                tasks: $this->taskPreview($team, $id, $now->utc()),
            );
        });
    }

    /**
     * Companies assigned to this member that are still in play.
     *
     * "Still in play" is the absence of a closing stage rather than the presence of an open one: a
     * lead with **no** stage sits in the default column (see the `pipeline_stage_id` migration), so
     * asking for stages whose outcome is null would drop every lead nobody has moved yet — which
     * is most of them.
     *
     * @return Builder<Lead>
     */
    private function openLeads(Team $team, int $member): Builder
    {
        return Lead::query()
            ->where('leads.team_id', $team->getKey())
            ->whereHas(
                'assignments',
                fn (Builder $query): Builder => $query->where('lead_assignments.user_id', $member),
            )
            ->whereDoesntHave(
                'pipelineStage',
                fn (Builder $query): Builder => $query->whereIn('outcome', StageOutcome::values()),
            );
    }

    /**
     * @return Builder<LeadTask>
     */
    private function openTasks(Team $team, int $member): Builder
    {
        return LeadTask::query()
            ->where('lead_tasks.team_id', $team->getKey())
            ->where('assigned_to', $member)
            ->where('status', LeadTaskStatus::Open)
            ->whereNotNull('due_at');
    }

    /**
     * Unactioned trigger events about a company this member owns.
     *
     * Counted over `matched_lead_id` — the lead an event is **about** — and not `lead_id`, which is
     * the lead an event was converted *into* (ADR 0021). A signal that is already a lead of its own
     * is no longer waiting on anybody.
     *
     * `representatives()` is the same predicate the feed and its tab counts use, so this figure and
     * the queue a member opens are the same number (S11: one event is one card, not one article).
     */
    private function waitingSignals(Team $team, int $member): int
    {
        return Signal::query()
            ->where('signals.team_id', $team->getKey())
            ->representatives()
            ->where('status', SignalStatus::New)
            ->whereIn('matched_lead_id', LeadAssignment::query()
                ->where('lead_assignments.team_id', $team->getKey())
                ->where('lead_assignments.user_id', $member)
                ->select('lead_id'))
            ->count();
    }

    /**
     * The next few tasks, overdue first.
     *
     * Ordered by `due_at` ascending, which puts the overdue ones at the top for free — a task that
     * went past its date three days ago has an earlier date than one due this afternoon. Tasks with
     * no date are excluded rather than sorted last: a panel titled "next up" is about dates, and a
     * dateless task is a note to self that belongs on the lead.
     *
     * @param  DateTimeInterface  $now  The instant the counts were taken against, in UTC.
     * @return list<MyTask>
     */
    private function taskPreview(Team $team, int $member, DateTimeInterface $now): array
    {
        $tasks = $this->openTasks($team, $member)
            ->with('lead:id,company_name')
            ->orderBy('due_at')
            ->limit(self::TASK_PREVIEW)
            ->get();

        $preview = [];

        foreach ($tasks as $task) {
            $preview[] = new MyTask(
                id: (int) $task->getKey(),
                lead_id: (int) $task->lead_id,
                title: (string) $task->title,
                // A task cannot exist without its lead (the foreign key cascades), but the relation
                // is still read defensively: a dashboard must not 500 over a display name.
                company: (string) ($task->lead->company_name ?? ''),
                due_at: $task->due_at?->format(DateTimeInterface::ATOM),
                overdue: $task->due_at !== null && $task->due_at->lessThan($now),
            );
        }

        return $preview;
    }
}
