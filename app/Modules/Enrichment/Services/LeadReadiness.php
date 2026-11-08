<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * How ready a lead is to be contacted (Q4): 0–100, deterministic, no AI and no network.
 *
 * **This is not a second fit score and must never be read as one.** `lead_scores.score` answers
 * *should we want them* and is deliberately blind to missing data, because a thin lead must not be
 * marked down for what we have not found out yet. That is right, and it is why an A-grade lead can
 * sit on the list with no website, no contact and no routable email. Readiness answers the other
 * question — *can we actually reach them* — so it is precisely a measure of what we hold, which
 * makes it the one figure that is **allowed** to punish missing data.
 *
 * It is a **record over the lead's own columns**, recomputed from them, never incremented (ADR
 * 0012's rule for a derived figure). No grade, no letter and no status colour: a readiness of 20 is
 * a gap in our data, not a verdict about the prospect, and dressing it as one would turn "we have
 * not enriched this yet" into "this company is poor".
 *
 * Weights live in `config/enrichment.php` so tuning is a config change rather than a deploy.
 */
final class LeadReadiness
{
    /**
     * The lead's readiness, or **null** when there is nothing to judge — a lead with no column
     * filled at all gets no figure rather than a zero, because "not computed" and "nothing to
     * reach them by" are different states and a zero reads as the second.
     */
    public function for(Lead $lead): ?int
    {
        $lead->loadMissing('contacts');

        /** @var array<string, int> $weights */
        $weights = (array) config('enrichment.readiness_weights', []);

        // An inferred contact is a guess, not something we can reliably reach them by, so it earns no
        // readiness: the three contact factors are measured over the contacts we actually read off a
        // page. A lead with nothing but inferred guesses therefore still scores low here — which is
        // the honest reading of "we only have a guess", and keeps readiness the one figure allowed to
        // punish missing data from turning a guess into a reachable contact.
        $contacts = $lead->contacts;
        $reachable = $contacts->reject(fn (LeadContact $contact): bool => $contact->is_inferred);

        $held = [
            // Somewhere to read about them, and the thing every later enrichment hangs off.
            'website' => $lead->website !== null && trim($lead->website) !== '',
            'industry' => $lead->industry !== null && trim($lead->industry) !== '',
            'company_size' => $lead->employee_range !== null && trim($lead->employee_range) !== '',
            'location' => $this->hasLocation($lead),
            // Somebody to write to, then the two things that decide whether writing would work.
            'contact' => $reachable->isNotEmpty(),
            'routable_email' => $reachable->contains(
                fn (LeadContact $contact): bool => $contact->email_verification_status === EmailVerificationStatus::MxFound,
            ),
            // `info@` is a letterbox; a named mailbox is a person who can answer.
            'named_contact' => $reachable->contains(
                fn (LeadContact $contact): bool => $contact->email !== null && ! $contact->is_role_based,
            ),
        ];

        $total = 0;
        $earned = 0;

        foreach ($held as $key => $hasIt) {
            $weight = max(0, (int) ($weights[$key] ?? 0));

            if ($weight === 0) {
                continue; // a weight of zero is a team's way of saying "this doesn't count"
            }

            $total += $weight;

            if ($hasIt) {
                $earned += $weight;
            }
        }

        if ($total === 0) {
            return null; // every weight switched off: there is no figure to give
        }

        if ($earned === 0 && ! $this->hasAnythingAtAll($lead)) {
            return null;
        }

        return (int) round($earned / $total * 100);
    }

    private function hasLocation(Lead $lead): bool
    {
        foreach ([$lead->locality, $lead->region, $lead->postal_code, $lead->country] as $part) {
            if ($part !== null && trim($part) !== '') {
                return true;
            }
        }

        return ($lead->locations ?? []) !== [];
    }

    /**
     * Whether we hold anything about this lead beyond the name it was discovered under. A bare name
     * earns no figure at all, for the same reason Scoring writes no row for a lead it could assess
     * nothing about: a number there would be a guess wearing a label.
     */
    private function hasAnythingAtAll(Lead $lead): bool
    {
        return $lead->company_number !== null
            || $lead->website !== null
            || $lead->industry !== null
            || $lead->employee_range !== null
            || $this->hasLocation($lead)
            || $lead->contacts->isNotEmpty();
    }
}
