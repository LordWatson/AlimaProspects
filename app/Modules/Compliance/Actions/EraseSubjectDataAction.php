<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Data\SubjectDataReport;
use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Compliance\Services\LeadAnonymiser;
use App\Modules\Compliance\Services\PersonalDataLocator;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Signals\Services\SignalsPersonalDataExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Honours a data-subject erasure request for one team (docs/compliance.md §3.5).
 *
 * **It re-locates before it erases.** The member is acting on a report they read a moment ago, and
 * between the two an enrichment pass may have added a contact or a colleague may have logged a
 * call. Acting on ids from the stale report would leave exactly the records the request was about.
 *
 * **An exact identity and a name are then treated differently**, which is the difference between
 * honouring a request and erasing a stranger:
 *
 * - For an **address or a phone number** the re-located set is erased whole. The identity is exact,
 *   so a contact derived since the search is the same person's and belongs in the erasure.
 * - For a **name** only the ids the member confirmed are erased. "John" matches people the request
 *   was never about; the locator calls its name results candidates for a human to confirm, and
 *   this is where that confirmation is actually honoured rather than merely documented. A name
 *   request that arrives with no selection erases nothing.
 *
 * What it does, per matched record:
 *
 * - **Contacts** are deleted. The name, title, address and number go.
 * - **Messages** lose their subject, body, variants and recipient address — the row stays, because
 *   the fact that something was sent is the team's accountability record and holds no personal data
 *   once the text is gone.
 * - **Approaches** lose their note, for the same reason.
 * - **Signals** are handed to the Signals module's own exporter, which erases the unconverted ones
 *   and reports the converted ones rather than tearing the provenance out from under a live lead
 *   (§5a).
 * - **A company record matched by name** — a sole trader, a one-person consultancy — is
 *   **anonymised, not deleted**. The match is a fuzzy one on a name, and deleting a real business
 *   on a fuzzy match would be its own data-protection failure. The team is told the company record
 *   remains and can delete it from the workspace if it is genuinely the person. ⚖️
 *
 * **And it suppresses the identity**, on the team's own list, with reason `erasure`. This is the
 * part that is easy to leave out and makes the rest pointless: without it the next discovery run
 * re-sources the same company, AI re-derives the same address, and the person who asked to be
 * forgotten hears from us again. The entry holds a one-way hash and a mask — never the address —
 * and cannot be lifted (see {@see SuppressionReason::isLiftable()}).
 *
 * Audited with the actor, because an erasure is somebody's decision (§3.8). The audit entry and the
 * log carry **counts only**: a log of what we erased for privacy reasons must not be a copy of it.
 */
final class EraseSubjectDataAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly PersonalDataLocator $locator,
        private readonly LeadAnonymiser $anonymiser,
        private readonly SignalsPersonalDataExporter $signals,
        private readonly SuppressionList $suppressions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>|null  $confirmedIds  The record ids the member actually saw and ticked.
     *                                        **Required for a name match** (see the class docblock);
     *                                        ignored for an address or a number, where the identity
     *                                        is exact and a record derived since the search is the
     *                                        same person's.
     * @return array{report: SubjectDataReport, erased: array<string, int>, retained: int, suppressed: bool}
     */
    public function handle(Team $team, string $query, User $actor, ?array $confirmedIds = null): array
    {
        $report = $this->locator->locate($team, $query);

        return $this->teamContext->run($team, function () use ($team, $report, $actor, $confirmedIds): array {
            // One transaction. The suppression is what makes the erasure stick, so it must not be
            // able to fail *after* the data is gone — that would leave the person erased and
            // re-contactable, which is the one outcome worse than doing nothing.
            [$erased, $suppressed] = DB::transaction(fn (): array => [
                $this->erase($team, $report, $confirmedIds),
                $this->suppressIdentity($team, $report, $actor),
            ]);

            $this->audit->log('compliance.subject_data_erased', null, [
                'matched_on' => $report->matchedOn,
                'erased' => $erased,
                'retained' => $report->total() - $report->erasableCount(),
                'suppressed' => $suppressed,
            ], $actor);

            // Counts only — never the query, which is the identity the subject asked us to forget
            // (CLAUDE.md logging rules, §3.9).
            Log::channel('compliance')->info('Data-subject erasure completed.', [
                'team_id' => (int) $team->getKey(),
                'matched_on' => $report->matchedOn,
                'erased' => $erased,
                'suppressed' => $suppressed,
            ]);

            return [
                'report' => $report,
                'erased' => $erased,
                'retained' => $report->total() - $report->erasableCount(),
                'suppressed' => $suppressed,
            ];
        });
    }

    /**
     * @param  list<int>|null  $confirmedIds
     * @return array<string, int> counts keyed by {@see SubjectDataCategory} value
     */
    private function erase(Team $team, SubjectDataReport $report, ?array $confirmedIds): array
    {
        $counts = [];

        foreach (SubjectDataCategory::cases() as $category) {
            $counts[$category->value] = 0;
        }

        $teamId = $team->getKey();

        $contactIds = $this->ids($report, SubjectDataCategory::Contact, $confirmedIds);
        $messageIds = $this->ids($report, SubjectDataCategory::Message, $confirmedIds);
        $attemptIds = $this->ids($report, SubjectDataCategory::Attempt, $confirmedIds);
        $leadIds = $this->ids($report, SubjectDataCategory::Lead, $confirmedIds);

        if ($messageIds !== []) {
            $counts[SubjectDataCategory::Message->value] = (int) OutreachMessage::query()
                ->where('team_id', $teamId)
                ->whereIn('id', $messageIds)
                ->update([
                    'subject' => null,
                    'body' => '',
                    'variants' => null,
                    'sent_to' => null,
                    'error_message' => null,
                ]);
        }

        if ($attemptIds !== []) {
            $counts[SubjectDataCategory::Attempt->value] = (int) ContactAttempt::query()
                ->where('team_id', $teamId)
                ->whereIn('id', $attemptIds)
                ->update(['notes' => null]);
        }

        // Contacts last of the three, because the messages and attempts were found *through* them
        // and the foreign keys are `nullOnDelete`: deleting the contact first would leave the ids
        // still valid but the trail to them gone.
        if ($contactIds !== []) {
            $counts[SubjectDataCategory::Contact->value] = (int) LeadContact::query()
                ->where('team_id', $teamId)
                ->whereIn('id', $contactIds)
                ->delete();
        }

        foreach ($leadIds as $leadId) {
            $lead = Lead::query()->where('team_id', $teamId)->find($leadId);

            if ($lead instanceof Lead && $this->anonymiser->handle($lead)) {
                $counts[SubjectDataCategory::Lead->value]++;
            }
        }

        // Signals answers for itself, by name, and decides what it may erase (§5a). Only asked
        // when the member confirmed at least one signal, for the same reason the intersection
        // above exists: its match is a name match too.
        if ($report->matchedOn === 'name' && $this->ids($report, SubjectDataCategory::Signal, $confirmedIds) !== []) {
            $counts[SubjectDataCategory::Signal->value] = (int) $this->signals->erase($team, $report->query)['erased'];
        }

        return $counts;
    }

    /**
     * Put the identity on the team's do-not-contact list, so the erasure is not undone by the next
     * discovery run. Returns false when there is nothing suppressible — a name-only request, which
     * the list cannot key on.
     */
    private function suppressIdentity(Team $team, SubjectDataReport $report, User $actor): bool
    {
        $type = match ($report->matchedOn) {
            'email' => SuppressionType::Email,
            'phone' => SuppressionType::Phone,
            default => null,
        };

        if ($type === null) {
            return false;
        }

        return $this->suppressions->suppress(
            type: $type,
            value: $report->query,
            // The team's own list, not the platform's: the request was made to **this** controller,
            // and another team may be processing the same person on a basis of its own that this
            // request says nothing about. Unliftable all the same — see the class docblock. ⚖️
            scope: SuppressionScope::Team,
            reason: SuppressionReason::Erasure,
            teamId: (int) $team->getKey(),
            actor: $actor,
        ) !== null;
    }

    /**
     * The ids of the erasable records in one category, narrowed to what the member confirmed.
     *
     * The **intersection is the whole point for a name match**. The report is re-located, so the
     * set here can include a record the member never saw — a contact an enrichment pass derived
     * since they read the screen. For an exact address that is right: it is the same person, and
     * erasing it is what they asked for. For a name it is not: "John" matches people the request
     * was never about, and erasing one of those on nobody's say-so is itself a data-protection
     * failure. So a name match erases **only ids the member ticked**, and nothing new sneaks in.
     *
     * @param  list<int>|null  $confirmedIds
     * @return list<int>
     */
    private function ids(SubjectDataReport $report, SubjectDataCategory $category, ?array $confirmedIds): array
    {
        $ids = [];

        foreach ($report->records as $record) {
            if ($record->category === $category && $record->erasable) {
                $ids[] = $record->id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($report->matchedOn !== 'name') {
            return $ids;
        }

        // A name match with nothing confirmed erases nothing. Deliberately not "erase everything":
        // a request that arrives without a selection is a request nobody chose.
        return array_values(array_intersect($ids, $confirmedIds ?? []));
    }
}
