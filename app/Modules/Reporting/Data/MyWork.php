<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Actions\BuildMyWorkAction;
use Spatie\LaravelData\Data;

/**
 * The dashboard's personal panel: what is waiting on **this member**, built by
 * {@see BuildMyWorkAction}.
 *
 * Everything above it on the screen is the team's position. This is the one block that is not, and
 * the distinction matters more than it looks: a manager reading "48 signals waiting" cannot tell
 * whether any of them are theirs, and a member on a ten-person team has no way to find the four
 * companies they own in a list of four hundred.
 *
 * Two kinds of figure, and they are scoped differently on purpose:
 *
 * - The **queues** (`open_leads`, `tasks_overdue`, `tasks_due_today`, `signals_waiting`) ignore the
 *   report window entirely. A task that went overdue last month is still overdue today, and
 *   hiding it because the filter says "last 7 days" would be a filter quietly deciding what a
 *   member has to do.
 * - The **activity** figures (`actions`, `companies_worked`, `approaches`) are scoped by the
 *   window, like every other number on the dashboard, because they are a record of a period.
 *
 * Which is why the panel says which of its figures move with the filter, rather than leaving a
 * reader to assume one rule covers both.
 */
final class MyWork extends Data
{
    /**
     * @param  list<MyTask>  $tasks  The next few tasks due, soonest first — overdue ones first of all.
     */
    public function __construct(
        /** The member's own display name, for the panel's heading. Data, not a translation key. */
        public readonly string $member,
        /** Companies assigned to them that are neither won nor lost. Not window-scoped. */
        public readonly int $open_leads,
        /** Open tasks assigned to them whose due date has passed. Not window-scoped. */
        public readonly int $tasks_overdue,
        /** Open tasks assigned to them due today in the team's timezone. Not window-scoped. */
        public readonly int $tasks_due_today,
        /** Unactioned trigger events about companies they own. Not window-scoped. */
        public readonly int $signals_waiting,
        /** Things they did in the window — the same timeline the team activity table counts. */
        public readonly int $actions,
        /** How many distinct companies those actions touched. */
        public readonly int $companies_worked,
        /** Of those actions, the ones that were an approach to a prospect. */
        public readonly int $approaches,
        public readonly array $tasks,
    ) {}
}
