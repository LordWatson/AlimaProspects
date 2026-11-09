<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * One task on the dashboard's personal panel — the next thing waiting on this member.
 *
 * A deliberately thin projection of `lead_tasks`: enough to recognise the job and click through to
 * the company it belongs to, and nothing else. The lead's workspace owns the task itself, so a
 * field added here would be a second place somebody could read a task's state from.
 *
 * `company` is **data, not a translation key** (unlike every label in this module) — it is the
 * prospect's own name and is printed exactly as stored.
 */
final class MyTask extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $lead_id,
        /** The task's own title, as the member typed it. */
        public readonly string $title,
        /** The company the task hangs off, printed as stored. */
        public readonly string $company,
        /** ISO-8601 UTC, or null for a task with no date on it. */
        public readonly ?string $due_at,
        /** Past its due date and still open. Said in words by the panel, never by colour alone. */
        public readonly bool $overdue,
    ) {}
}
