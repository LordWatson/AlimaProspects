<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use Spatie\LaravelData\Data;

/**
 * What one signal type would cost a team per month, if they used it (S9).
 *
 * **Shown in the form, before anything runs.** A signal definition is a standing instruction to
 * spend money: every template becomes a paid search on every run of every watch that uses the type,
 * multiplied by the places those watches cover (ADR 0019 §4). Nothing else on the screen says so,
 * and a member adding a tenth template has no way to know they have just tripled a bill.
 *
 * The number is deliberately **approximate and stated as such**. A real month depends on how many
 * watches the type ends up on, whether a run trims to its ceiling, and how much the AI pass costs —
 * so this is the search cost, which is the part that scales with the thing the member is editing.
 */
final class SignalCostEstimate extends Data
{
    public function __construct(
        /** Templates on the definition being edited. */
        public readonly int $templates,
        /** Searches one round of every watch using it would ask for. */
        public readonly int $queriesPerRun,
        /** How many times a month those watches run, summed. */
        public readonly int $runsPerMonth,
        /** Searches a month, before any per-run trim. */
        public readonly int $queriesPerMonth,
        public readonly int $costMicrosPerMonth,
        /** How many active watches this was computed over. */
        public readonly int $watchCount,
        /**
         * True when the team has no active watch yet, so this is "if you add it to one daily watch"
         * rather than a fact about watches they have. The screen says which — an estimate presented
         * as a measurement is worse than no estimate.
         */
        public readonly bool $hypothetical,
    ) {}
}
