<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Writes a team's leads out as CSV (Phase 10b), honouring whatever the leads list is currently
 * filtered to — an export is "give me what I'm looking at", not "give me everything".
 *
 * **Streamed in chunks** (`leads-workspace.csv.export_chunk`): a team with tens of thousands of
 * leads must not need tens of thousands of rows in memory, or a request long enough to time out
 * before the first byte is sent.
 *
 * The column set is the same one {@see LeadCsvImporter} reads, so an export can be edited in a
 * spreadsheet and imported back without anyone having to work out a mapping — a round trip is the
 * most common reason a team exports at all.
 */
final class LeadCsvExporter
{
    /**
     * The header row, and the order of every data row. Stage, tags and assignees are the team's own
     * work and come last, after the facts about the company.
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

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadQueryBuilder $leadQuery,
    ) {}

    /**
     * Yields the header followed by one row per lead, as arrays ready for `fputcsv`.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(Team $team, LeadFilters $filters): Generator
    {
        yield self::COLUMNS;

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

        while (true) {
            /** @var Collection<int, Lead> $leads */
            $leads = $this->teamContext->run($team, fn (): Collection => $this->leadQuery
                ->filtered($team, $filters)
                ->with(['contacts', 'activeScore', 'pipelineStage', 'tags', 'assignments.user:id,name'])
                // Keyset paging on `id`, not an offset: an export that takes a while must not skip
                // or repeat a lead because another member added one while it ran.
                ->where('leads.id', '>', $lastId)
                ->reorder('leads.id')
                ->limit($chunk)
                ->get());

            if ($leads->isEmpty()) {
                return;
            }

            foreach ($leads as $lead) {
                $lastId = (int) $lead->getKey();

                yield $this->row($lead, $defaultStage);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function row(Lead $lead, ?string $defaultStage): array
    {
        return array_map(
            fn (string $cell): string => $this->neutralise($cell),
            $this->cells($lead, $defaultStage),
        );
    }

    /**
     * Stop a spreadsheet treating a cell as a formula.
     *
     * This matters more here than it looks: a lead's company name can come from a web-search result
     * or from a CSV the team was sent, so a company called `=HYPERLINK("http://evil.test","Click")`
     * is a cell an Owner or Admin then opens in Excel. Prefixing an apostrophe makes it text; Excel
     * and LibreOffice both hide the prefix, and {@see LeadCsvImporter} strips it on the way back in,
     * so a round trip is unaffected.
     */
    private function neutralise(string $cell): string
    {
        return Str::startsWith($cell, ['=', '+', '-', '@', "\t", "\r"])
            ? "'".$cell
            : $cell;
    }

    /**
     * @return list<string>
     */
    private function cells(Lead $lead, ?string $defaultStage): array
    {
        $contact = $this->bestContact($lead);

        return [
            (string) $lead->company_name,
            (string) $lead->company_number,
            (string) $lead->website,
            (string) $lead->industry,
            (string) $lead->employee_range,
            (string) $lead->locality,
            (string) $lead->region,
            (string) $lead->postal_code,
            (string) $lead->country,
            $lead->source->value,
            $lead->enrichment_status->value,
            $lead->activeScore?->grade->value ?? '',
            $lead->activeScore === null ? '' : (string) $lead->activeScore->score,
            $this->text($contact?->name),
            $this->text($contact?->title),
            $this->text($contact?->email),
            $this->text($contact?->phone),
            // Null means the default column, not "nowhere".
            $this->text($lead->pipeline_stage_id === null ? $defaultStage : $lead->pipelineStage->name),
            $lead->tags->pluck('name')->implode(', '),
            $lead->assignments->map(static fn ($a): string => (string) $a->user?->name)->filter()->implode(', '),
            (string) $lead->source_url,
            $this->date($lead->collected_at),
            $this->date($lead->enriched_at),
        ];
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
