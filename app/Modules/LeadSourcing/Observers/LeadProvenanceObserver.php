<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Observers;

use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Data\EvidenceItem;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;

/**
 * Writes every new lead's own provenance as its first item of evidence (PL1).
 *
 * **Why an observer rather than a call in each Action.** Leads enter from five places today —
 * discovery, a CSV import, a member typing one in, a signal conversion, a de-duplication merge —
 * and PL10 adds a sixth. The acceptance criterion is that *every* lead has at least one item of
 * evidence, and the only way to make that structural rather than a thing each new path has to
 * remember is to hang it off the row being created. A sixth path that forgot to record provenance
 * would not fail a test; it would quietly ship leads with no confidence.
 *
 * It is a deliberately small exception to this codebase's "logic lives in Actions" rule: the
 * observer decides nothing and composes nothing, it restates what is already on the row
 * ({@see EvidenceItem::provenanceOf()}) and hands it to the one Action allowed to write evidence.
 *
 * **A converted signal is skipped**, because {@see ConvertSignalToLeadAction}
 * writes the article's own evidence — dated, titled and attributed to the outlet — in the same
 * transaction. A baseline row beside it would be the same fact twice, with the weaker of the two
 * labels.
 */
final class LeadProvenanceObserver
{
    public function __construct(private readonly RecordLeadEvidenceAction $record) {}

    public function created(Lead $lead): void
    {
        if ($lead->source === LeadSource::Signal) {
            return;
        }

        // No team passed: the suppression threshold is resolved from the ambient `TeamContext`
        // inside the recalculation, which memoises it by id — so a discovery run creating two
        // hundred leads costs one query for the team rather than two hundred, and nothing here
        // lazy-loads a relation inside a `created` hook.
        $this->record->handle($lead, EvidenceItem::provenanceOf($lead));
    }
}
