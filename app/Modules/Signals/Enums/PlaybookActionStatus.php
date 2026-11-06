<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * What became of one step of a playbook on one signal (S14).
 *
 * Five cases, and the distinctions between them are the whole value of the run log. "It didn't
 * happen" is not one answer: an automation nobody can interrogate is an automation nobody trusts,
 * and the first question anybody asks is *why not*.
 *
 * - {@see self::Applied} — done.
 * - {@see self::Simulated} — a rehearsal. Would have been done.
 * - {@see self::Refused} — a rule said no. The compliance refusal lives here, and it is the one
 *   status the log surfaces on its own.
 * - {@see self::Skipped} — the step did not apply: no lead to act on, no match to attach to, a
 *   stage or member that belongs to nobody. Not a failure, and not something to retry.
 * - {@see self::Failed} — the module's own Action threw. Worth seeing, and the one status that
 *   means "somebody should look at this".
 *
 * Values are stable storage keys written into every `signal_playbook_runs.results` row.
 */
enum PlaybookActionStatus: string
{
    case Applied = 'applied';
    case Simulated = 'simulated';
    case Refused = 'refused';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Done',
            self::Simulated => 'Would have run',
            self::Refused => 'Refused',
            self::Skipped => 'Not applicable',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the log should draw attention to this. A refusal and a failure both mean a person
     * should read the line; the rest are the rule working.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Refused, self::Failed => true,
            self::Applied, self::Simulated, self::Skipped => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
