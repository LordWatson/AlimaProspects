<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CsvCell;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Signals\Models\Signal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use ZipArchive;

/**
 * Writes a team's own data to a ZIP of CSVs (docs/compliance.md §3.6) — portability, and the
 * practical answer to "we want our data out".
 *
 * **CSV, not JSON**, because the people who ask for this open it in a spreadsheet. Every file is
 * written through the same hardening the rest of the platform's exports use: a UTF-8 byte-order
 * mark so Excel reads accented and Arabic text, and {@see CsvCell} on every cell so a company
 * called `=HYPERLINK(...)` is text rather than a formula. The data in these cells came from a web
 * search, an AI extraction or a file somebody was sent — never from us.
 *
 * **Read in chunks.** Each table is paged through by id, so the models held at once are bounded and
 * a team with fifty thousand leads produces a file rather than an out-of-memory error. One table's
 * finished text is held at a time, because a ZIP entry has to be added whole.
 *
 * Synchronous by design at MVP volumes, and metered per member (the `compliance-export` limiter). A
 * queued export writing to object storage with a signed link is the documented next step; the shape
 * here — one closure per file, driven off a list — makes that a change of destination rather than a
 * rewrite.
 *
 * **The do-not-contact list is exported masked.** It is the team's own data and they are entitled to
 * it, but the table holds a hash and a preview and no column holds an address in the clear (§3.9) —
 * so the export cannot show one either, and the README says so.
 */
final class TeamDataArchiver
{
    /** Rows per read. Small enough to bound memory, large enough that the query count stays sane. */
    private const int CHUNK = 500;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * Build the archive and return the path to it. The caller sends it and deletes it
     * (`BinaryFileResponse::deleteFileAfterSend()`).
     *
     * @throws RuntimeException when a temporary file or the archive cannot be created
     */
    public function write(Team $team): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alima-export-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Could not create the export archive.');
        }

        $this->teamContext->run($team, function () use ($team, $zip): void {
            $zip->addFromString('README.txt', $this->readme($team));

            foreach ($this->files($team) as $name => $build) {
                $zip->addFromString($name, $build());
            }
        });

        $zip->close();

        return $path;
    }

    /**
     * Every file in the archive: its name, and a closure that produces it.
     *
     * A list rather than a method per table, so adding a table is one entry and nothing can be
     * half-added — a file with no header, or a header with no file. Closures rather than eager
     * strings so only one table's text exists at a time.
     *
     * @return array<string, Closure(): string>
     */
    private function files(Team $team): array
    {
        $teamId = (int) $team->getKey();

        return [
            'leads.csv' => fn (): string => $this->csv(
                Lead::query()->where('team_id', $teamId),
                ['id', 'company_name', 'company_number', 'website', 'industry', 'employee_range', 'locality', 'region', 'postal_code', 'country', 'source', 'status', 'source_url', 'collected_at', 'anonymised_at'],
                static fn (Lead $lead): array => [
                    $lead->id,
                    $lead->company_name,
                    $lead->company_number,
                    $lead->website,
                    $lead->industry,
                    $lead->employee_range,
                    $lead->locality,
                    $lead->region,
                    $lead->postal_code,
                    $lead->country,
                    $lead->source->value,
                    $lead->status->value,
                    $lead->source_url,
                    $lead->collected_at?->toIso8601String(),
                    $lead->anonymised_at?->toIso8601String(),
                ],
            ),
            'contacts.csv' => fn (): string => $this->csv(
                LeadContact::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'name', 'title', 'email', 'phone', 'is_role_based', 'is_personal', 'source', 'source_url', 'collected_at'],
                static fn (LeadContact $contact): array => [
                    $contact->id,
                    $contact->lead_id,
                    $contact->name,
                    $contact->title,
                    $contact->email,
                    $contact->phone,
                    $contact->is_role_based ? '1' : '0',
                    $contact->is_personal ? '1' : '0',
                    $contact->source,
                    $contact->source_url,
                    $contact->collected_at?->toIso8601String(),
                ],
            ),
            'scores.csv' => fn (): string => $this->csv(
                LeadScore::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'icp_id', 'score', 'grade', 'excluded', 'scored_at'],
                static fn (LeadScore $score): array => [
                    $score->id,
                    $score->lead_id,
                    $score->icp_id,
                    $score->score,
                    $score->grade->value,
                    $score->excluded ? '1' : '0',
                    $score->scored_at?->toIso8601String(),
                ],
            ),
            'timeline.csv' => fn (): string => $this->csv(
                LeadActivity::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'type', 'user_id', 'properties', 'occurred_at'],
                static fn (LeadActivity $activity): array => [
                    $activity->id,
                    $activity->lead_id,
                    $activity->type->value,
                    $activity->user_id,
                    // The structured detail, as JSON. The timeline stores values rather than
                    // sentences (the app is EN/AR), so a readable column would mean picking a
                    // language for somebody else's file.
                    (string) json_encode($activity->properties ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $activity->occurred_at->toIso8601String(),
                ],
            ),
            'notes.csv' => fn (): string => $this->csv(
                LeadNote::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'user_id', 'body', 'created_at'],
                static fn (LeadNote $note): array => [
                    $note->id,
                    $note->lead_id,
                    $note->user_id,
                    $note->body,
                    $note->created_at?->toIso8601String(),
                ],
            ),
            'tasks.csv' => fn (): string => $this->csv(
                LeadTask::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'title', 'status', 'assigned_to', 'due_at', 'completed_at'],
                static fn (LeadTask $task): array => [
                    $task->id,
                    $task->lead_id,
                    $task->title,
                    $task->status->value,
                    $task->assigned_to,
                    $task->due_at?->toIso8601String(),
                    $task->completed_at?->toIso8601String(),
                ],
            ),
            'outreach_messages.csv' => fn (): string => $this->csv(
                OutreachMessage::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'lead_contact_id', 'channel', 'locale', 'status', 'subject', 'body', 'sent_to', 'sent_at'],
                static fn (OutreachMessage $message): array => [
                    $message->id,
                    $message->lead_id,
                    $message->lead_contact_id,
                    $message->channel->value,
                    $message->locale->value,
                    $message->status->value,
                    $message->subject,
                    $message->body,
                    $message->sent_to,
                    $message->sent_at?->toIso8601String(),
                ],
            ),
            'contact_attempts.csv' => fn (): string => $this->csv(
                ContactAttempt::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'lead_contact_id', 'channel', 'outcome', 'notes', 'occurred_at'],
                static fn (ContactAttempt $attempt): array => [
                    $attempt->id,
                    $attempt->lead_id,
                    $attempt->lead_contact_id,
                    $attempt->channel->value,
                    $attempt->outcome->value,
                    $attempt->notes,
                    $attempt->occurred_at->toIso8601String(),
                ],
            ),
            'signals.csv' => fn (): string => $this->csv(
                Signal::query()->where('team_id', $teamId),
                ['id', 'lead_id', 'status', 'event_type', 'subject_person_name', 'subject_person_role', 'subject_company_name', 'headline', 'source_url', 'published_at'],
                static fn (Signal $signal): array => [
                    $signal->id,
                    $signal->lead_id,
                    $signal->status->value,
                    $signal->event_type->value,
                    $signal->subject_person_name,
                    $signal->subject_person_role,
                    $signal->subject_company_name,
                    $signal->headline,
                    $signal->source_url,
                    $signal->published_at?->toIso8601String(),
                ],
            ),
            // The team's own entries only. A platform-wide opt-out belongs to the person who made
            // it and to every team it binds, not to one tenant's export.
            'do_not_contact.csv' => fn (): string => $this->csv(
                OutreachSuppression::query()->where('team_id', $teamId),
                ['id', 'type', 'masked_value', 'reason', 'note', 'suppressed_at'],
                static fn (OutreachSuppression $entry): array => [
                    $entry->id,
                    $entry->type->value,
                    // Masked, because that is all the table holds (§3.9). See the README.
                    $entry->value_preview,
                    $entry->reason->value,
                    $entry->note,
                    $entry->suppressed_at->toIso8601String(),
                ],
            ),
            'lawful_basis.csv' => fn (): string => $this->csv(
                LiaRecord::query()->where('team_id', $teamId),
                ['id', 'market', 'status', 'version', 'purpose', 'necessity', 'balancing', 'safeguards', 'signals_scope', 'completed_at', 'review_due_at', 'withdrawn_at'],
                static fn (LiaRecord $record): array => [
                    $record->id,
                    $record->market->value,
                    $record->status->value,
                    $record->version,
                    $record->purpose,
                    $record->necessity,
                    $record->balancing,
                    $record->safeguards,
                    $record->signals_scope,
                    $record->completed_at?->toIso8601String(),
                    $record->review_due_at?->toIso8601String(),
                    $record->withdrawn_at?->toIso8601String(),
                ],
            ),
            'retention_policy.csv' => fn (): string => $this->csv(
                RetentionPolicy::query()->where('team_id', $teamId),
                ['id', 'is_enabled', 'uncontacted_lead_days', 'contacted_lead_days', 'last_swept_at', 'last_deleted_count', 'last_anonymised_count'],
                static fn (RetentionPolicy $policy): array => [
                    $policy->id,
                    $policy->is_enabled ? '1' : '0',
                    $policy->uncontacted_lead_days,
                    $policy->contacted_lead_days,
                    $policy->last_swept_at?->toIso8601String(),
                    $policy->last_deleted_count,
                    $policy->last_anonymised_count,
                ],
            ),
        ];
    }

    /**
     * One CSV as a string, paged through by id.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  callable(TModel): list<string|int|null>  $row
     */
    private function csv(Builder $query, array $columns, callable $row): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer for the export.');
        }

        // BOM first: without it Excel reads a UTF-8 file as the system codepage and Arabic becomes
        // mojibake (the same reasoning as the reporting export).
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, $columns);

        $query->chunkById(self::CHUNK, function (Collection $models) use ($handle, $row): void {
            foreach ($models as $model) {
                fputcsv($handle, array_map(
                    static fn (string|int|null $cell): string => CsvCell::neutralise((string) $cell),
                    $row($model),
                ));
            }
        });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * What is in the archive and what is deliberately not. Written in English only: it is a
     * technical note to whoever receives the file, not product copy.
     */
    private function readme(Team $team): string
    {
        $lines = [
            'Alima — team data export',
            '========================',
            '',
            'Team: '.$team->name.' (id '.$team->getKey().')',
            'Generated: '.now()->toIso8601String().' (UTC)',
            '',
            'This archive contains the data this team holds in Alima, one CSV per table. Every file',
            'is UTF-8 with a byte-order mark so that spreadsheets read accented and Arabic text',
            'correctly. Timestamps are ISO-8601 in UTC; your dashboard shows them in the team',
            'timezone ('.$team->timezone.').',
            '',
            'Files',
            '-----',
            '  leads.csv              Company records sourced for this team.',
            '  contacts.csv           Contact points derived for those companies.',
            '  scores.csv             ICP-fit scores.',
            '  timeline.csv           What happened to each lead, as structured values.',
            '  notes.csv              Notes your team wrote.',
            '  tasks.csv              Tasks on leads.',
            '  outreach_messages.csv  Drafted and sent messages.',
            '  contact_attempts.csv   Approaches your team logged.',
            '  signals.csv            News signals and what they were converted to.',
            '  do_not_contact.csv     Your own do-not-contact entries — see below.',
            '  lawful_basis.csv       Your recorded legitimate-interest assessments.',
            '  retention_policy.csv   Your retention windows and when they last ran.',
            '',
            'Notes',
            '-----',
            'do_not_contact.csv shows a MASKED value (for example j***@example.com), not the address.',
            'Alima stores only a one-way hash of each suppressed identity plus that mask: a register',
            'of people who want nothing to do with us is not somewhere to keep their contact details.',
            'The mask is enough to recognise an entry you added; it is not enough to contact anyone.',
            '',
            'Platform-wide opt-outs are not included. They belong to the people who made them and are',
            'honoured for every team, so they are not one team\'s data to export.',
            '',
            'A lead with anonymised_at set has had its personal data removed under your retention',
            'policy. The company record and the record of each approach remain; the contacts, message',
            'text and notes do not.',
        ];

        return implode("\n", $lines)."\n";
    }
}
