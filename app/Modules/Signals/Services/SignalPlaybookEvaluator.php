<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookFacts;
use App\Modules\Signals\Models\SignalPlaybook;

/**
 * Decides which playbooks fire on a signal (S14). **Deterministic, pure, and no AI.**
 *
 * That is the requirement rather than a preference. A rule a member built has to behave the same way
 * on Tuesday as it did in the preview on Monday, or the preview is worthless and so is the dry run
 * — and the two brakes this phase leans on hardest are both predictions about what the live rule
 * will do. A classifier here would make both of them guesses.
 *
 * So it takes {@see SignalPlaybookConditions} and {@see SignalPlaybookFacts} — nine scalars — and
 * touches no database, no container and no clock. Every condition is a table row in
 * `SignalPlaybookEvaluatorTest`, which is the only way to be sure about a matcher with eight
 * independent clauses.
 *
 * Two rules about the shape of the answer are worth stating because they are easy to get backwards:
 *
 * - **An empty list means "don't care", not "match nothing".** A new rule with nothing filled in
 *   matches every signal, which is what somebody building one expects to see before they narrow it.
 * - **Unknown is not zero.** A signal with no `heat` does **not** clear a heat floor, and a signal
 *   with no amount does not sit inside an amount band. Treating null as zero would make a rule about
 *   big deals fire on every story Alima could not read a number out of — the same reasoning that
 *   made `signals.heat` nullable in the first place (ADR 0022).
 */
final class SignalPlaybookEvaluator
{
    /**
     * Whether this signal satisfies every condition the rule sets.
     */
    public function matches(SignalPlaybookConditions $conditions, SignalPlaybookFacts $facts): bool
    {
        if ($conditions->definitionIds !== []
            && ($facts->definitionId === null
                || ! in_array($facts->definitionId, $conditions->definitionIds, strict: true))) {
            // A signal whose definition has been deleted matches no type-scoped rule. It still
            // carries `event_key`, but a rule names an id, and silently matching on a key the
            // member never chose would be a different rule from the one they wrote.
            return false;
        }

        if ($conditions->sourceKinds !== []
            && ! in_array($facts->sourceKind, $conditions->sourceKinds, strict: true)) {
            return false;
        }

        if ($conditions->jurisdictions !== []
            && ($facts->jurisdiction === null
                || ! in_array($facts->jurisdiction, $conditions->jurisdictions, strict: true))) {
            // An unresolvable place fails the condition. Note which way that cuts: a rule narrowed
            // to the UK does not fire on a story we could not place, which is the conservative
            // answer for a rule that may go on to draft an approach (docs/compliance.md §3.11's
            // reasoning, one level up).
            return false;
        }

        if ($conditions->minHeat !== null && ($facts->heat === null || $facts->heat < $conditions->minHeat)) {
            return false;
        }

        if ($conditions->minAmountMinor !== null
            && ($facts->amountMinor === null || $facts->amountMinor < $conditions->minAmountMinor)) {
            return false;
        }

        if ($conditions->maxAmountMinor !== null
            && ($facts->amountMinor === null || $facts->amountMinor > $conditions->maxAmountMinor)) {
            return false;
        }

        if (! $conditions->watchlist->matches($facts->hasMatchedLead)) {
            return false;
        }

        if ($conditions->watchlistIds !== []
            && ($facts->watchlistId === null
                || ! in_array($facts->watchlistId, $conditions->watchlistIds, strict: true))) {
            return false;
        }

        return true;
    }

    /**
     * The rules that fire on this signal, in the order they run.
     *
     * `$playbooks` is expected in run order already — `SignalPlaybook::runnable()` is the query that
     * produces it — and this honours `stop_after_first_match`: a matching rule with that flag is
     * included and nothing after it is considered. The flag is on the rule rather than on the team
     * because it is a statement about *this* rule's place in the order ("if it's an acquisition,
     * handle it here and stop"), and a team-wide switch would make every rule's behaviour depend on
     * an unrelated setting.
     *
     * **A rehearsing rule never stops the chain**, and that was a defect found in review. A rule in
     * dry run that matched with the flag set would swallow the signal for the live rule below it,
     * so switching a rule to rehearse would silently change what the rest of the team's automation
     * did. The one promise a rehearsal makes is that it changes nothing, and that has to include
     * the other rules.
     *
     * A **cluster member** matches nothing, and the check is here rather than in the query: one
     * event is one card and a member never actions a member row (S11), so a rule must not either —
     * eight rules firing on eight articles about one acquisition would convert the same company
     * eight times, which the lead de-duplicator would survive and the run log would not explain.
     *
     * @param  list<SignalPlaybook>  $playbooks
     * @return list<SignalPlaybook>
     */
    public function select(array $playbooks, SignalPlaybookFacts $facts): array
    {
        if (! $facts->isClusterRepresentative) {
            return [];
        }

        $firing = [];

        foreach ($playbooks as $playbook) {
            if (! $this->matches($playbook->conditionSet(), $facts)) {
                continue;
            }

            $firing[] = $playbook;

            if ($playbook->stop_after_first_match && $playbook->mode->performs()) {
                break;
            }
        }

        return $firing;
    }
}
