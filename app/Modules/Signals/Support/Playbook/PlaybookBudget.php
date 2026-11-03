<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\Signals\Enums\PlaybookActionKind;

/**
 * What one job is still allowed to spend (S14) — the third of the phase's four brakes.
 *
 * Counted **per job, across every playbook**, not per playbook. That is the distinction that makes
 * it a brake rather than a formality: three rules each converting twenty-five signals is
 * seventy-five leads, and a team that hit the ceiling three times over would have been charged for
 * all of it. The ceiling is on the morning, not on the rule.
 *
 * Two counters rather than one, because the two things cost differently and a team's tolerance for
 * them is different. A conversion spends a lead against the plan's allowance and queues an
 * enrichment; a draft spends AI credit. Sharing one counter would let a rule that drafts nothing
 * convert twice as much as the number somebody configured.
 *
 * A rehearsal (`PlaybookMode::DryRun`) does not consume either, and that is deliberate: the
 * rehearsal's whole job is to report what a live rule *would* have done, and a dry run that stopped
 * counting at twenty-five would under-report the very thing somebody is about to switch on. The
 * cap is still reported in the simulated log, by the executor asking
 * {@see self::wouldExceedConversions()} — so the log says "and it would have hit the ceiling here"
 * rather than quietly stopping.
 */
final class PlaybookBudget
{
    private int $conversions = 0;

    private int $drafts = 0;

    private int $simulatedConversions = 0;

    private int $simulatedDrafts = 0;

    public function __construct(
        private readonly int $maxConversions,
        private readonly int $maxDrafts,
    ) {}

    /**
     * The budget from configuration. Both floors are 0 rather than 1: a deployment that wants
     * playbooks to touch nothing expensive should be able to say so.
     */
    public static function fromConfig(): self
    {
        return new self(
            maxConversions: max(0, (int) config('signals.playbooks.max_conversions_per_run', 25)),
            maxDrafts: max(0, (int) config('signals.playbooks.max_drafts_per_run', 10)),
        );
    }

    /**
     * Whether this rule's chain can afford to run **at all** right now.
     *
     * Asked **before the signal is claimed**, and that is the point. The first cut of this phase
     * checked the budget inside each handler, which meant a signal arriving after the ceiling was
     * reached got a claimed run row with a `*_cap_reached` refusal on it — and the claim is
     * permanent, so the rule never got another chance at that signal. With a 200-signal batch and a
     * 25-conversion ceiling, 175 signals were refused **for ever** by design (found in the S14
     * review).
     *
     * So a cap is now a **deferral** rather than a refusal: the signal is left unclaimed and the
     * job re-dispatches the remainder, which is the same treatment the batch ceiling gets. The
     * in-handler checks stay as the backstop for what this cannot see — a chain that spends twice.
     *
     * @param  list<PlaybookActionKind>  $kinds  the chain's step kinds, in order
     */
    public function canAfford(array $kinds, bool $rehearsing): bool
    {
        foreach ($kinds as $kind) {
            if (! $kind->spends()) {
                continue;
            }

            $affordable = $kind === PlaybookActionKind::DraftOutreach
                ? ($rehearsing ? $this->mayDraftInRehearsal() : $this->mayDraft())
                : ($rehearsing ? $this->mayConvertInRehearsal() : $this->mayConvert());

            if (! $affordable) {
                return false;
            }
        }

        return true;
    }

    public function mayConvert(): bool
    {
        return $this->conversions < $this->maxConversions;
    }

    public function mayDraft(): bool
    {
        return $this->drafts < $this->maxDrafts;
    }

    public function recordConversion(): void
    {
        $this->conversions++;
    }

    public function recordDraft(): void
    {
        $this->drafts++;
    }

    /**
     * Whether a *rehearsal* has run out by now.
     *
     * Separate counters from the live ones because a rehearsal does not consume the budget, so it
     * has to count its own hypotheticals to be able to say "this is where a live rule would have
     * stopped". Kept **here** rather than on the handler, because the handlers are container
     * singletons that outlive one job: a counter on a handler would carry last job's total into
     * this one, and the budget is built fresh per job precisely so it cannot.
     */
    public function mayConvertInRehearsal(): bool
    {
        return $this->simulatedConversions < $this->maxConversions;
    }

    public function mayDraftInRehearsal(): bool
    {
        return $this->simulatedDrafts < $this->maxDrafts;
    }

    public function recordRehearsedConversion(): void
    {
        $this->simulatedConversions++;
    }

    public function recordRehearsedDraft(): void
    {
        $this->simulatedDrafts++;
    }
}
