<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Contracts;

use App\Modules\LeadSourcing\Models\Lead;

/**
 * What a lead has been seen *doing* — the seam that lets the deterministic scorer judge an ICP's
 * **buying signal** criteria (Q1 of `lead-quality-plan.md`, phase Q3).
 *
 * Until Q3 `DeterministicScorer` abstained on every buying signal, on the grounds that funding,
 * hiring and expansion "aren't in any column we hold". Signals has held them since S10:
 * `signals.matched_lead_id` is *the lead an event is about*. This contract is how Scoring asks,
 * without importing a module it must not depend on — the pattern Billing uses for `CreditSource`
 * and Outreach for `SignalOutreachDrafter`.
 *
 * **The default is permissive, and that is the right direction here.** `NoLeadSignals` answers "no
 * labels", which makes every buying-signal criterion `Unknown` — exactly the behaviour before Q3,
 * and a true statement about a team with Signals switched off. It is the opposite choice to
 * `SignalOutreachDrafter`, whose default *refuses*, because that one answers whether a prospect may
 * be approached and this one answers whether we happen to know something.
 */
interface LeadSignalSource
{
    /**
     * The matchable text of what this lead has been seen doing — a signal's type name, its event
     * key and its headline — newest first.
     *
     * The implementation decides what counts: how recent, which statuses, one entry per *event*
     * rather than per article. The scorer only matches strings, and an empty list means `Unknown`,
     * never a miss.
     *
     * @return list<string>
     */
    public function labelsFor(Lead $lead): array;
}
