<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Data;

use App\Modules\AiInsights\Services\ActivitySnapshotBuilder;
use App\Modules\AiPlatform\Support\UntrustedContent;
use Spatie\LaravelData\Data;

/**
 * A week of a team's prospecting, as numbers (Phase 14) — what the AI digest is given to reason
 * about, built by {@see ActivitySnapshotBuilder}.
 *
 * **Numbers only, and that is a design rule rather than an economy.** No company name, no member
 * name, no ICP title, no stage name, nothing a member typed — so there is no free text in the
 * prompt at all, and therefore nothing in it to inject. It is the one AI call in the platform that
 * needs no {@see UntrustedContent} wrapper, and leaving names out is what buys that. It also keeps
 * the input a few hundred tokens whatever size the team is.
 *
 * The consequence is a real limitation, written down here so nobody treats it as a bug: the digest
 * can say "your reply rate on LinkedIn is three times your email rate" and cannot say "chase
 * Acme". Naming a company is the deterministic insights' job, and they do it from the database.
 */
final class ActivitySnapshot extends Data
{
    /**
     * @param  array<string, int|float>  $metrics  Flat, named figures for the window.
     * @param  array<string, array<string, int|float>>  $channels  Per outreach channel, keyed by
     *                                                             the channel's stored value.
     */
    public function __construct(
        /** `Y-m-d` in the team's timezone, or null when the team has no history yet. */
        public ?string $periodStart,
        /** `Y-m-d` in the team's timezone. */
        public ?string $periodEnd,
        public array $metrics,
        public array $channels = [],
    ) {}

    /**
     * Figures that are **standing totals rather than activity**, and so cannot by themselves make
     * a week worth interpreting. A dormant account with three overdue tasks has not done anything;
     * it has three overdue tasks, and it would otherwise buy an AI call every single day to be
     * told so.
     *
     * @var list<string>
     */
    private const array STANDING_METRICS = ['open_tasks_overdue'];

    /**
     * Whether there is anything here worth paying a model to interpret.
     *
     * This is the cost guard the phase's risk register asks for: a team that did nothing this week
     * gets **no AI call at all**, rather than a daily bill for the model explaining that nothing
     * happened. Quiet is the common case for a trial account, and it is the expensive one if
     * nobody checks.
     *
     * The test is `!== 0`, not `> 0`, and that is the interesting part: a `*_change` figure is
     * negative when a team did plenty last week and nothing this week. Under `> 0` that week reads
     * as quiet and the model is never asked — losing exactly the insight most worth having, which
     * is that the work stopped.
     */
    public function isQuiet(): bool
    {
        foreach ($this->metrics as $key => $value) {
            if (in_array($key, self::STANDING_METRICS, strict: true)) {
                continue;
            }

            if ($value !== 0 && $value !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * What goes into the prompt's `{{activity}}` placeholder: canonical JSON, so an identical week
     * produces an identical string and the AI Platform's response cache can actually hit.
     */
    public function toPromptValue(): string
    {
        return (string) json_encode([
            'period' => ['from' => $this->periodStart, 'to' => $this->periodEnd],
            'metrics' => $this->metrics,
            'by_channel' => $this->channels,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
