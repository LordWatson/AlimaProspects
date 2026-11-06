<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\DeterministicScore;
use App\Modules\Scoring\Services\DeterministicScorer;
use App\Modules\Signals\Models\Signal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * How well a signal's subject fits the team's ideal customer — asked of **`Scoring`'s own criteria
 * match**, not of a second implementation (S12).
 *
 * The plan was explicit about this and it is the right instruction: a module with two fit scores
 * has two definitions of fit, and the second one is the one that drifts. So rather than
 * re-implementing geography and seniority matching over a signal's columns, this builds the lead
 * the signal **would become** — in memory, saved nowhere — and asks {@see DeterministicScorer} the
 * question it already answers.
 *
 * Three things make that honest rather than a trick:
 *
 * - **The mapping is conversion's own.** The fields come from the same places
 *   `ConvertSignalToLeadAction` takes them from, so "the fit of this signal" and "the fit of the
 *   lead it makes" cannot disagree. If that mapping changes, both change together.
 * - **Nothing is invented.** A signal carries a company name, a public role, a place and an event;
 *   it carries no industry, no employee count and no technologies. Those criteria therefore see an
 *   empty field and come back **Unknown**, which `DeterministicScorer` already treats as "leave
 *   this out of the ratio" rather than as a miss — so a signal is never marked down for being a
 *   news article rather than an enriched company record.
 * - **It abstains when the ICP has nothing to say.** No active ICP, or an ICP whose every
 *   criterion is about a dimension a signal has none of, means {@see DeterministicScore::hasSignals()}
 *   is false and this returns null, and the scorer drops the component's weight from both sides of
 *   its average.
 *
 * The in-memory models are **never saved**, and the relations are set explicitly rather than
 * loaded: `DeterministicScorer` calls `loadMissing('contacts')`, and on an unsaved model that
 * would be a query with a null key.
 */
final class SignalIcpFit
{
    public function __construct(private readonly DeterministicScorer $scorer) {}

    /**
     * The signal's 0–100 fit against this ICP, or null when nothing could be assessed.
     *
     * An ICP **exclusion** that matches returns 0 rather than null, and that is the correct
     * reading: "your own profile rules this out" is a judgement, not an absence of one.
     */
    public function score(Signal $signal, ?Icp $icp): ?int
    {
        if (! $icp instanceof Icp) {
            return null;
        }

        $result = $this->scorer->score($this->asLead($signal), $icp);

        if ($result->excluded) {
            return 0;
        }

        return $result->hasSignals() ? $result->score : null;
    }

    /**
     * What this signal would look like as a lead — the same fields conversion writes, on a model
     * with no key that is never persisted.
     */
    private function asLead(Signal $signal): Lead
    {
        $lead = new Lead([
            'team_id' => $signal->team_id,
            'company_name' => $signal->subject_company_name,
            // The place the article named, and the jurisdiction resolved from it. Geography
            // criteria are matched against both, because a team's ICP may name "Dubai" or "AE"
            // and the signal knows one of each (S8).
            'locality' => $signal->location,
            'country' => $signal->jurisdiction,
        ]);

        // Set, not loaded. The role is the only thing a signal knows about a person that an ICP
        // can judge — `JobTitle` and `Seniority` criteria both read contact titles — so the
        // subject becomes the one contact, exactly as conversion makes them.
        $lead->setRelation('contacts', $this->contacts($signal));

        return $lead;
    }

    /**
     * The subject as a single unsaved contact, or an empty collection when the article named no
     * role.
     *
     * A name with no role is deliberately **not** a contact here: a title criterion matched against
     * a person's name would match nothing useful and an empty haystack is the honest input, which
     * `DeterministicScorer` reads as Unknown.
     *
     * @return EloquentCollection<int, LeadContact>
     */
    private function contacts(Signal $signal): EloquentCollection
    {
        $role = trim((string) $signal->subject_person_role);

        if ($role === '') {
            /** @var EloquentCollection<int, LeadContact> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        $contact = new LeadContact([
            'team_id' => $signal->team_id,
            'name' => $signal->subject_person_name,
            'title' => $role,
        ]);

        /** @var EloquentCollection<int, LeadContact> $contacts */
        $contacts = new EloquentCollection([$contact]);

        return $contacts;
    }
}
