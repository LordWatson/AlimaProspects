<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadImportResult;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Services\LeadCsvImporter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Brings a team's own list of companies in from a CSV (Phase 10b).
 *
 * The things that matter here:
 *
 * - **De-duplication on the way in.** A row is matched against the leads the team already has by
 *   `dedupe_key` — company number first, then website domain, then the company name — and an
 *   existing lead is **filled in, never overwritten**: only fields the lead doesn't already hold
 *   are taken from the CSV. A team importing a spreadsheet twice ends up with one copy of each
 *   company and keeps whatever enrichment had already found.
 * - **The source is recorded honestly.** An imported lead is attributed to the import, not to a
 *   provider, and is marked enrichment-`pending` so the normal pipeline can fill it in later. It is
 *   never passed off as a registry record.
 * - **One transaction per row.** A file with a bad row half way down imports the other rows rather
 *   than rolling the whole upload back; the result says which rows were skipped and why.
 * - **No AI, no network.** Importing is a read of the team's own file.
 */
final class ImportLeadsAction
{
    /**
     * The fields an import can map to. Deliberately a short, flat set: a CSV from a team's old
     * spreadsheet is company facts and one contact, and anything richer belongs to enrichment.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'company_name',
        'company_number',
        'website',
        'industry',
        'employee_range',
        'locality',
        'region',
        'postal_code',
        'country',
        'contact_name',
        'contact_title',
        'contact_email',
        'contact_phone',
        'tags',
    ];

    /** What a contact imported from a CSV records as its provenance (ADR 0008). */
    public const string CONTACT_SOURCE = 'csv_import';

    public const string CONTACT_METHOD = 'import';

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadCsvImporter $csv,
        private readonly SyncLeadTagsAction $syncTags,
        private readonly RecordLeadActivityAction $record,
        private readonly EnsureDefaultPipelineStagesAction $ensureStages,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $mapping  target field => CSV column name
     */
    public function handle(Team $team, User $actor, string $path, array $mapping): LeadImportResult
    {
        return $this->teamContext->run($team, function () use ($team, $actor, $path, $mapping): LeadImportResult {
            $stage = $this->ensureStages->handle($team)->firstWhere('is_default', true);

            $created = 0;
            $merged = 0;
            $skipped = [];
            $truncated = $this->csv->exceedsLimit($path);

            foreach ($this->csv->rows($path, $this->allowed($mapping)) as [$rowNumber, $values]) {
                $name = $values['company_name'] ?? '';

                if ($name === '') {
                    $skipped[] = ['row' => $rowNumber, 'reason' => LeadImportResult::NO_COMPANY_NAME];

                    continue;
                }

                try {
                    $outcome = DB::transaction(fn (): string => $this->upsert($team, $actor, $values, $stage));
                } catch (UniqueConstraintViolationException) {
                    // Two imports of the same file at once: the row lock can't hold a row that
                    // doesn't exist yet, so whoever lost the race merges into the winner instead.
                    try {
                        $outcome = DB::transaction(fn (): string => $this->upsert($team, $actor, $values, $stage));
                    } catch (Throwable $e) {
                        $skipped[] = ['row' => $rowNumber, 'reason' => LeadImportResult::COULD_NOT_SAVE];
                        $this->logSkipped($team, $rowNumber, $e);

                        continue;
                    }
                } catch (Throwable $e) {
                    // One bad row must not roll back the rows that were fine, or lose the audit
                    // entry and the report for the whole upload.
                    $skipped[] = ['row' => $rowNumber, 'reason' => LeadImportResult::COULD_NOT_SAVE];
                    $this->logSkipped($team, $rowNumber, $e);

                    continue;
                }

                $outcome === 'created' ? $created++ : $merged++;
            }

            $this->audit->log('leads.imported', null, [
                'created' => $created,
                'merged' => $merged,
                'skipped' => count($skipped),
                // The mapped field names, not the team's column headings or any of their data.
                'fields' => array_keys($this->allowed($mapping)),
            ], $actor);

            return new LeadImportResult(
                created: $created,
                merged: $merged,
                skipped: $skipped,
                truncated: $truncated,
            );
        });
    }

    /**
     * @param  array<string, string>  $values
     * @return 'created'|'merged'
     */
    private function upsert(Team $team, User $actor, array $values, ?PipelineStage $stage): string
    {
        $dedupeKey = $this->dedupeKey($values);

        $lead = Lead::query()
            ->where('team_id', $team->getKey())
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->first();

        // Every value is clamped to its column's width. A spreadsheet cell has no length limit and
        // the columns do, so an over-long cell would otherwise be a "data too long" 500 half way
        // through a file — and the rows already committed would have no audit entry to show for it.
        $fields = [
            'company_number' => $this->clamp($values['company_number'] ?? '', 255),
            'website' => $this->safeUrl($values['website'] ?? ''),
            'industry' => $this->clamp($values['industry'] ?? '', 255),
            'employee_range' => $this->clamp($values['employee_range'] ?? '', 64),
            'locality' => $this->clamp($values['locality'] ?? '', 255),
            'region' => $this->clamp($values['region'] ?? '', 255),
            'postal_code' => $this->clamp($values['postal_code'] ?? '', 255),
            'country' => $this->country($values['country'] ?? ''),
        ];

        if ($lead === null) {
            $lead = Lead::query()->create([
                'team_id' => $team->getKey(),
                // Not a provider: `LeadSource::Import` says plainly that the team brought this in
                // themselves, and it outranks every provider on a dedupe merge — so a later
                // discovery hit fills in what the row was missing rather than renaming the company
                // the team typed.
                'source' => LeadSource::Import,
                'enrichment_status' => EnrichmentStatus::Pending,
                'company_name' => $this->clamp($values['company_name'], 255),
                'pipeline_stage_id' => $stage?->getKey(),
                'collected_at' => now(),
                'dedupe_key' => $dedupeKey,
                ...array_filter($fields, static fn (string $value): bool => $value !== ''),
            ]);

            $isNew = true;
        } else {
            $isNew = false;

            // Fill in the blanks only. The CSV is the team's older, hand-kept copy; whatever
            // enrichment has since found about the company is better data, not worse.
            $gaps = [];

            foreach ($fields as $column => $value) {
                if ($value !== '' && ($lead->getAttribute($column) === null || $lead->getAttribute($column) === '')) {
                    $gaps[$column] = $value;
                }
            }

            if ($gaps !== []) {
                $lead->forceFill($gaps)->save();
            }
        }

        $this->importContact($lead, $values);

        if (($values['tags'] ?? '') !== '') {
            $this->syncTags->handle($team, $lead, array_values([
                ...$lead->tags()->pluck('name')->all(),
                ...$this->splitTags($values['tags']),
            ]), $actor);
        }

        $this->record->handle(
            lead: $lead,
            type: LeadActivityType::Imported,
            properties: ['merged' => ! $isNew],
            actor: $actor,
        );

        return $isNew ? 'created' : 'merged';
    }

    /**
     * Add the row's contact, if it brings one the lead doesn't already have. Contacts are matched on
     * the email, because that is the only part of a contact row that identifies a person reliably.
     *
     * Note what is **not** done here: nothing is marked verified. A contact from a team's own
     * spreadsheet has had no MX check and no provenance beyond "they typed it", so it is stored
     * with the import as its source and left for the normal verification to judge. See
     * docs/compliance.md on preferring role-based contacts.
     *
     * @param  array<string, string>  $values
     */
    private function importContact(Lead $lead, array $values): void
    {
        $email = Str::lower($this->clamp($values['contact_email'] ?? '', 255));
        $name = $this->clamp($values['contact_name'] ?? '', 255);
        $title = $this->clamp($values['contact_title'] ?? '', 255);
        $phone = $this->clamp($values['contact_phone'] ?? '', 64);

        // An address that isn't one is worse than none: it would be stored, shown and eventually
        // mailed. The free-tier verifier re-checks it properly; this is just the obvious filter.
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        if ($email === '' && $name === '' && $phone === '') {
            return;
        }

        // Matched on the email where there is one, else on the name — so re-importing the same
        // spreadsheet doesn't stack up a fresh copy of every contact that happens to have no
        // address.
        $existing = $email !== ''
            ? $lead->contacts()->where('email', $email)->first()
            : ($name !== '' ? $lead->contacts()->whereNull('email')->where('name', $name)->first() : null);

        if ($existing !== null) {
            return;
        }

        $lead->contacts()->create([
            'team_id' => $lead->team_id,
            'name' => $name === '' ? null : $name,
            'title' => $title === '' ? null : $title,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
            'source' => self::CONTACT_SOURCE,
            'method' => self::CONTACT_METHOD,
            'collected_at' => now(),
        ]);
    }

    /**
     * The identity the import de-duplicates on: company number if there is one, else the website's
     * registrable domain, else the normalised company name.
     *
     * The first two use **exactly** the form Lead Sourcing uses, so an imported company and a
     * discovered one collapse into one lead. The name fallback does not — Lead Sourcing's name key
     * includes the provider that found it — so a name-only import never merges with a discovered
     * lead. That is recorded as a known limitation rather than papered over: matching on a bare
     * name across sources is exactly the false-merge Enrichment's de-duplication refuses to do.
     *
     * Clamped to the column's 191 characters, since a spreadsheet cell has no such limit.
     *
     * @param  array<string, string>  $values
     */
    private function dedupeKey(array $values): string
    {
        $number = Str::lower(Str::squish($values['company_number'] ?? ''));

        if ($number !== '') {
            return $this->clamp('no:'.$number, 191);
        }

        $website = $this->safeUrl($values['website'] ?? '');

        if ($website !== '') {
            $host = parse_url($website, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return $this->clamp('domain:'.Str::lower(Str::replaceStart('www.', '', $host)), 191);
            }
        }

        $name = $values['company_name'];

        return $this->clamp('name:'.(Str::slug($name) ?: Str::lower(Str::squish($name))), 191);
    }

    /**
     * A two-letter ISO code upper-cased, or null — the column is `char(2)`, and a team that typed
     * "United Kingdom" should not have it silently truncated to "UN".
     */
    private function country(string $value): string
    {
        return Str::length($value) === 2 ? Str::upper($value) : '';
    }

    /**
     * Trim a cell to its column's width. Multibyte-safe, so clamping an Arabic company name can't
     * leave a half character behind.
     */
    private function clamp(string $value, int $max): string
    {
        return Str::limit(Str::squish($value), $max, '');
    }

    /**
     * A website only if it is one we are willing to render as a link. The detail screen turns this
     * column into an `<a href>`, so a cell containing `javascript:…` has to not reach it — the same
     * rule {@see SourcedLead} applies to provider data.
     */
    private function safeUrl(string $value): string
    {
        $value = $this->clamp($value, 255);

        if ($value === '') {
            return '';
        }

        $candidate = Str::startsWith(Str::lower($value), ['http://', 'https://'])
            ? $value
            // A bare domain is what a spreadsheet usually holds, and is safe to assume https for.
            : (preg_match('/^[\pL\pN][\pL\pN.\-]*\.[\pL]{2,}/u', $value) === 1 ? 'https://'.$value : '');

        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $this->clamp($candidate, 255);
    }

    /**
     * Tags in one cell, however the spreadsheet separated them — including the **Arabic** comma,
     * which an Arabic-language spreadsheet will have used (ADR 0007: the UAE is an MVP market).
     *
     * @return list<string>
     */
    private function splitTags(string $value): array
    {
        /** @var list<string> $tags */
        $tags = collect(preg_split('/[,;|،؛]/u', $value) ?: [])
            ->map(static fn (string $tag): string => Str::limit(Str::squish($tag), 40, ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $tags;
    }

    /**
     * A row the database refused. The row number and the driver's message go to the log so someone
     * can see *why*; the member is told which row, because the fix is in their spreadsheet.
     */
    private function logSkipped(Team $team, int $rowNumber, Throwable $exception): void
    {
        Log::channel('lead_sourcing')->warning('Skipped a CSV row the database refused.', [
            'team_id' => $team->getKey(),
            'row' => $rowNumber,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Drop anything the caller mapped that isn't an importable field, so a crafted mapping can't
     * name a column on `leads` that imports have no business writing.
     *
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private function allowed(array $mapping): array
    {
        return array_intersect_key($mapping, array_flip(self::FIELDS));
    }
}
