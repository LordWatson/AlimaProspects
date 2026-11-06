<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalPlaybook;

/**
 * Whether a {@see SignalPlaybook} does what it says, or only records what it would have done (S14).
 *
 * An enum rather than an `is_dry_run` boolean, and ADR 0018 §1's test is why: the executor branches
 * hard on this — a dry run calls no module's Action, spends nothing and reaches nobody — so it is a
 * closed set the code reasons about rather than data a team owns.
 *
 * Two cases and no third. A "paused" playbook is `is_active = false`, which is a different
 * statement: paused means *do not evaluate me*, where dry run means *evaluate me and tell me what
 * you would have done*. Folding them together would lose the rehearsal, which is the one brake that
 * makes somebody brave enough to switch a rule on.
 *
 * Values are stable storage keys; the labels are translation keys the screens pass through `t()`
 * (EN/AR, ADR 0007).
 */
enum PlaybookMode: string
{
    /** Evaluate, and record what *would* have happened. Nothing is written outside the run log. */
    case DryRun = 'dry_run';

    /** Evaluate and act. */
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::DryRun => 'Rehearsal',
            self::Live => 'Live',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DryRun => 'Records what it would have done. Nothing is changed and nobody is told.',
            self::Live => 'Does it. Every decision is still written to the log.',
        };
    }

    /**
     * Whether an action under this mode may actually call a module's Action.
     *
     * Read this rather than comparing cases: every handler asks the same question, and a third mode
     * (a "one rule at a time" review mode has been discussed) must be caught by all of them without
     * anyone revisiting eight `match` expressions that still compile.
     */
    public function performs(): bool
    {
        return $this === self::Live;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
