<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CsvCell;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Contracts\SubscriberTypeResolver;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\ExportPreset;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes a team's leads out as CSV (Phase 10b, extended by PL6), honouring whatever the leads list
 * is currently filtered to — an export is "give me what I'm looking at", not "give me everything".
 *
 * **Streamed in chunks** (`leads-workspace.csv.export_chunk`): a team with tens of thousands of
 * leads must not need tens of thousands of rows in memory, or a request long enough to time out
 * before the first byte is sent.
 *
 * A **preset is a list of column keys** ({@see ExportPreset}), and every row is built as a keyed
 * map of every column this exporter can produce and then projected onto the chosen keys. That is
 * why a custom column set costs nothing extra, and it is why {@see self::COLUMNS} can stay exactly
 * as it was: the column set {@see LeadCsvImporter} reads back, in its original order, so an export
 * can be edited in a spreadsheet and imported again without duplicating anything.
 *
 * ## Three rules PL6 adds, and all three are about what must **not** reach the file
 *
 * - **Only revealed values.** A value a member has not paid to see is written as an empty cell, not
 *
 *   as the masked form a screen shows: `j***@acme.com` in a CRM is worse than nothing, because
 *   something will eventually try to send to it.
 * - **A suppressed company is left out altogether**, and counted. Once a row is in a spreadsheet on
 *   somebody's laptop no later opt-out can reach it, which is the one place "we honour the list at
 *   the point of sending" is not good enough (`docs/compliance.md` §3.2).
 * - **A suppressed value is blanked** even where the company is fine. The company is not the
 *   address, and an address somebody asked us to stop using is not ours to hand over.
 */
final class LeadCsvExporter
{
    /**
     * The header row, and the order of every data row, for the importable preset.
     *
     * **Do not reorder or extend this.** {@see LeadCsvImporter} reads it back, which is the most
     * common reason a team exports at all; a column added here changes a file somebody's
     * spreadsheet is already shaped around. Everything PL6 adds is in the other presets.
     *
     * Stage, tags and assignees are the team's own work and come last, after the facts about the
     * company.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'company_name',
        'company_number',
        'website',
        'industry',
        'employee_range',
        'locality',
        'region',
        'postal_code',
        'country',
        'source',
        'enrichment_status',
        'grade',
        'score',
        'contact_name',
        'contact_title',
        'contact_email',
        'contact_phone',
        'stage',
        'tags',
        'assignees',
        'source_url',
        'collected_at',
        'enriched_at',
    ];

    /**
     * Every column an export can carry — what a custom column set is validated against (PL6).
     *
     * @var list<string>
     */
    public const array ALL_COLUMNS = [
        ...self::COLUMNS,
        'timing_score',
        'confidence',
        'why_now_summary',
        'evidence_1',
        'evidence_2',
        'evidence_3',
        'contact_email_status',
        'contact_phone_type',
        'contact_tps_status',
        'pecr_subscriber_type',
        'lead_url',
    ];

    /** How many pieces of evidence a row carries, as `evidence_1` … `evidence_N`. */
    private const int EVIDENCE_COLUMNS = 3;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadQueryBuilder $leadQuery,
        private readonly ContactApprovalGate $approval,
        private readonly SubscriberTypeResolver $subscriberTypes,
    ) {}

    /**
     * Yields the header followed by the data rows, as arrays ready for `fputcsv`.
     *
     * The generator **returns** the tally — rows written and companies left out — which is what the
     * receipt on `lead_exports` and the member's summary are built from. Read it with
     * `$generator->getReturn()` once the walk is finished.
     *
     * @param  list<string>  $columns  a custom column set; empty means the preset's own
     * @return Generator<int, list<string>, void, array{rows: int, suppressed: int}>
     */
    public function rows(
        Team $team,
        LeadFilters $filters,
        ExportPreset $preset = ExportPreset::LeadsOnly,
        array $columns = [],
    ): Generator {
        $keys = $this->resolveColumns($preset, $columns);

        yield $keys;

        $chunk = max(1, (int) config('leads-workspace.csv.export_chunk', 200));

        // A lead with no stage is in the default column (see the `pipeline_stage_id` migration), so
        // the export has to name that column rather than leave the cell blank — otherwise the file
        // disagrees with the board about where the team's newest leads are.
        $defaultStage = $this->teamContext->run($team, fn (): ?string => PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->where('is_default', true)
            ->value('name'));

        // The generator is consumed while the response streams, so the team context is set around
        // each chunk rather than around the whole walk — by the time the second chunk is fetched,
        // the request's own context may be long gone.
        $lastId = 0;
        $rows = 0;
        $suppressed = 0;
        $relations = $this->relationsFor($keys);

        while (true) {
            /** @var Collection<int, Lead> $leads */
            $leads = $this->teamContext->run($team, fn (): Collection => $this->leadQuery
                ->filtered($team, $filters)
                ->with($relations)
                // Keyset paging on `id`, not an offset: an export that takes a while must not skip
                // or repeat a lead because another member added one while it ran.
                ->where('leads.id', '>', $lastId)
                ->reorder('leads.id')
                ->limit($chunk)
                ->get());

            if ($leads->isEmpty()) {
                return ['rows' => $rows, 'suppressed' => $suppressed];
            }

            foreach ($leads as $lead) {
                $lastId = (int) $lead->getKey();

                if ($this->isSuppressedCompany($team, $lead)) {
                    $suppressed++;

                    continue;
                }

                foreach ($this->contactsFor($lead, $preset) as $contact) {
                    $rows++;

                    yield $this->row($team, $lead, $contact, $defaultStage, $keys);
                }
            }
        }
    }

    /**
     * The contacts a lead contributes a row for.
     *
     * One row per lead in every preset but the CRM-ready one, which writes a row per contact with
     * the company columns repeated — that is what a CRM's own importer expects, and a team
     * otherwise does it with a spreadsheet formula. A lead with **no** contacts is still one row
     * either way: a company worth exporting is worth exporting before anybody has found a person
     * there.
     *
     * @return list<LeadContact|null>
     */
    private function contactsFor(Lead $lead, ExportPreset $preset): array
    {
        if (! $preset->rowPerContact()) {
            return [$this->bestContact($lead)];
        }

        $contacts = array_values($lead->contacts->all());

        return $contacts === [] ? [null] : $contacts;
    }

    /**
     * Whether this lead's company is on the do-not-contact list.
     *
     * The company-level question, asked of the domain: "stop contacting anyone at this company" has
     * to survive the export, because a row that has left in a spreadsheet is beyond the reach of
     * every later check. A lead with no website cannot be judged this way and is exported — its
     * contact values are still checked one by one.
     *
     * shortcut: one indexed lookup per lead rather than one per chunk, which is fine for the sizes
     * `leads-workspace.export.sync_max_rows` and the queued job deal with; batch the domains per
     * chunk if an export ever has to run over six figures of leads.
     */
    private function isSuppressedCompany(Team $team, Lead $lead): bool
    {
        $website = (string) ($lead->website ?? '');

        if ($website === '') {
            return false;
        }

        return ! $this->approval->allowsDomain($team, $website);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function row(Team $team, Lead $lead, ?LeadContact $contact, ?string $defaultStage, array $keys): array
    {
        $cells = $this->cells($team, $lead, $contact, $defaultStage);

        // Shared with the report exporter: a cell a spreadsheet would read as a formula is the
        // same hazard wherever it is written (see {@see CsvCell}).
        return array_map(
            static fn (string $key): string => CsvCell::neutralise($cells[$key] ?? ''),
            $keys,
        );
    }

    /**
     * Every column this exporter can produce, for one (lead, contact) pair.
     *
     * Built whole and projected, rather than branching per preset: a preset that had to know how to
     * build its own cells would be the place a column quietly means something different in one file
     * than in another.
     *
     * @return array<string, string>
     */
    private function cells(Team $team, Lead $lead, ?LeadContact $contact, ?string $defaultStage): array
    {
        $cells = [
            'company_name' => (string) $lead->company_name,
            'company_number' => (string) $lead->company_number,
            'website' => (string) $lead->website,
            'industry' => (string) $lead->industry,
            'employee_range' => (string) $lead->employee_range,
            'locality' => (string) $lead->locality,
            'region' => (string) $lead->region,
            'postal_code' => (string) $lead->postal_code,
            'country' => (string) $lead->country,
            'source' => $lead->source->value,
            'enrichment_status' => $lead->enrichment_status->value,
            'grade' => $lead->activeScore?->grade->value ?? '',
            'score' => $lead->activeScore === null ? '' : (string) $lead->activeScore->score,
            'contact_name' => $this->text($contact?->name),
            'contact_title' => $this->text($contact?->title),
            'contact_email' => $this->email($team, $contact),
            'contact_phone' => $this->phone($team, $contact),
            // Null means the default column, not "nowhere".
            'stage' => $this->text($lead->pipeline_stage_id === null ? $defaultStage : $lead->pipelineStage->name),
            'tags' => $lead->tags->pluck('name')->implode(', '),
            'assignees' => $lead->assignments->map(static fn ($a): string => (string) $a->user?->name)->filter()->implode(', '),
            'source_url' => (string) $lead->source_url,
            'collected_at' => $this->date($lead->collected_at),
            'enriched_at' => $this->date($lead->enriched_at),
            // --- PL1: why this lead, and how much of it to believe --------------------------
            'timing_score' => $this->number($lead->timing_score),
            'confidence' => $this->number($lead->confidence),
            'why_now_summary' => $this->text($lead->why_now_summary),
            // --- PL5: the screening verdicts -----------------------------------------------
            'contact_email_status' => $contact?->email_verification_status->value ?? '',
            'contact_phone_type' => $contact?->phone_type->value ?? '',
            // `unchecked` is written out as itself. A blank cell would read as "fine", and the
            // whole point of the column is that unknown is not clear (ADR 0032).
            'contact_tps_status' => $contact === null ? '' : $contact->tps_status->value,
            'pecr_subscriber_type' => $this->subscriberTypes->subscriberTypeFor($lead)['value'] ?? '',
            // Back to the lead in Alima, so a row in a CRM is one click from its evidence.
            'lead_url' => route('leads.show', ['lead' => $lead->getKey()]),
        ];

        return [...$cells, ...$this->evidenceCells($lead)];
    }

    /**
     * The top pieces of evidence, each as one cell: `title | date | source | url`.
     *
     * One cell rather than four columns per item, because twelve evidence columns would make the
     * file unreadable in the one application teams open it in — and because the evidence is read
     * rather than filtered on in a spreadsheet. Newest first, and a disputed item is left out
     * entirely: that is what a dispute means everywhere else (ADR 0028).
     *
     * @return array<string, string>
     */
    private function evidenceCells(Lead $lead): array
    {
        $items = $lead->relationLoaded('evidence')
            ? $lead->evidence
                ->reject(static fn (LeadEvidence $item): bool => $item->is_disputed)
                ->sortByDesc(fn (LeadEvidence $item): string => $this->date($item->observed_at ?? $item->captured_at))
                ->values()
            : null;

        $cells = [];

        for ($index = 0; $index < self::EVIDENCE_COLUMNS; $index++) {
            $item = $items?->get($index);

            $cells['evidence_'.($index + 1)] = $item instanceof LeadEvidence
                ? implode(' | ', [
                    (string) $item->title,
                    $this->date($item->observed_at ?? $item->captured_at),
                    (string) ($item->source_label ?? $item->source),
                    (string) $item->source_url,
                ])
                : '';
        }

        return $cells;
    }

    /**
     * The address, or nothing at all.
     *
     * Two reasons a cell is empty and neither is "we don't have one": a value a member has not paid
     * to see ({@see LeadContact::requiresEmailReveal()}), and one the team may not use. The masked
     * form a screen shows is deliberately **not** written — see the class note.
     */
    private function email(Team $team, ?LeadContact $contact): string
    {
        if ($contact === null || $contact->email === null || $contact->requiresEmailReveal()) {
            return '';
        }

        return $this->approval->allowsEmail($team, $contact->email) ? $contact->email : '';
    }

    private function phone(Team $team, ?LeadContact $contact): string
    {
        $value = $contact?->phoneValue();

        if ($contact === null || $value === null || $contact->requiresPhoneReveal()) {
            return '';
        }

        return $this->approval->allowsPhone($team, $value) ? $value : '';
    }

    /**
     * The relations a row needs, and no more: the evidence is a second query per chunk and the
     * importable preset has no column that reads it.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function relationsFor(array $keys): array
    {
        $relations = ['contacts', 'activeScore', 'pipelineStage', 'tags', 'assignments.user:id,name'];

        foreach ($keys as $key) {
            if (str_starts_with($key, 'evidence_')) {
                $relations[] = 'evidence';

                break;
            }
        }

        return $relations;
    }

    /**
     * The columns to write, in order — the member's own set where there is one, else the preset's.
     *
     * A custom set is narrowed to the keys this exporter actually produces: the request validates
     * them too, and this is the second check, because a stored column list written by an older
     * release must open minus the column that no longer exists rather than write a blank header.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function resolveColumns(ExportPreset $preset, array $columns): array
    {
        $keys = $columns === [] ? $preset->columns() : $columns;

        $keys = array_values(array_intersect($keys, self::ALL_COLUMNS));

        return $keys === [] ? self::COLUMNS : $keys;
    }

    /**
     * The one contact to put on the row: a role-based, non-personal address wins, because that is
     * the one the team is allowed to use (docs/compliance.md). Null when the lead has none —
     * plenty don't, and the row is still worth exporting.
     */
    private function bestContact(Lead $lead): ?LeadContact
    {
        return $lead->contacts
            ->sortByDesc(static fn (LeadContact $c): int => ($c->is_role_based ? 2 : 0) + ($c->is_personal ? 0 : 1))
            ->first();
    }

    /**
     * An empty cell rather than a missing one: every row has every column, so the file opens as a
     * rectangle in a spreadsheet.
     */
    private function text(?string $value): string
    {
        return $value ?? '';
    }

    /**
     * A blank cell for an unjudged figure, never a zero — null is "nobody has scored this", and a
     * zero in a spreadsheet is a judgement (ADR 0028).
     */
    private function number(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * ISO-8601 in UTC — unambiguous in a spreadsheet, and what the importer reads back.
     *
     * `DateTimeInterface` rather than Carbon: the app runs on CarbonImmutable
     * (AppServiceProvider), which is a sibling of Illuminate\Support\Carbon, not a subclass.
     */
    private function date(?DateTimeInterface $value): string
    {
        return $this->text($value?->format(DateTimeInterface::ATOM));
    }
}
