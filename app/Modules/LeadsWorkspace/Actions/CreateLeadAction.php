<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\CreateLeadData;
use App\Modules\LeadsWorkspace\Data\LeadCreationResult;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Support\OwnLeadFields;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Adds **one** company a member typed in themselves (Phase 10c) — met at an event, referred by a
 * client, spotted somewhere Alima doesn't look. The lead then goes through the same Enrichment →
 * Scoring → Workspace pipeline as a discovered or imported one; nothing downstream knows or cares
 * that a person typed it.
 *
 * The things that matter here:
 *
 * - **A company the team already has is filled in, never duplicated.** The lead is keyed exactly as
 *   a CSV import keys one ({@see OwnLeadFields::dedupeKey()}), so adding "Bright Bean Coffee" by
 *   hand and then importing the spreadsheet that already had it leaves one lead. An existing row is
 *   attached to and only its **empty** columns are filled: whatever enrichment has since found
 *   about the company is better data than a line typed at a stand, and the member is told plainly
 *   that this is what happened ({@see LeadCreationResult}).
 * - **Submitting twice must not make two leads.** `(team_id, dedupe_key)` is unique, the matching
 *   row is locked for the transaction, and a lost insert race is retried rather than surfaced — a
 *   double tap on a phone lands on one lead.
 * - **The contact is stored as given, and never passed off as verified.** A card handed over at an
 *   event has had no MX check, so the contact is written with the member as its provenance and
 *   `email_verification_status` left null for the normal verification to judge. A named individual
 *   sets `is_personal` and the lead's `has_personal_data`, which is what the compliance screens and
 *   the DSAR hook read.
 * - **Nothing external happens inside the transaction.** Enrichment is queued from `afterCommit`,
 *   so a rolled-back create can't leave a job chasing a lead that doesn't exist.
 *
 * Deliberately **not** done here: {@see LeadDeduplicator} is not
 * called. It can merge records keyed differently (a registry number here, a domain there) but it
 * does so by deleting the loser, and a hand-added lead ranks with an import — above every provider
 * — so a company typed in at an event could win against a discovered lead and take its notes and
 * tasks down with it. The exact-key attach above covers the cases a member can see, and the
 * de-duplicator still gets its say when the new lead is enriched.
 */
final class CreateLeadAction
{
    /** What a contact a member typed in records as its provenance (ADR 0008). */
    public const string CONTACT_SOURCE = 'manual';

    public const string CONTACT_METHOD = 'manual';

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SyncLeadTagsAction $syncTags,
        private readonly CreateLeadNoteAction $createNote,
        private readonly RecordLeadActivityAction $record,
        private readonly EnsureDefaultPipelineStagesAction $ensureStages,
        // Injected for its role-mailbox classification only — a pure config lookup. Verification
        // itself resolves DNS, which has no business running inside this write.
        private readonly EmailVerifier $emailVerifier,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $actor, CreateLeadData $data): LeadCreationResult
    {
        return $this->teamContext->run($team, function () use ($team, $actor, $data): LeadCreationResult {
            // Resolved before the transaction opens: giving a team its first board is a write of
            // its own (which catches its own unique violation), and it has no business happening
            // inside this action's lock window — or being re-run on each retry below.
            $stageId = $this->stageId($team, $data);

            try {
                $result = $this->runCreate($team, $actor, $data, $stageId);
            } catch (UniqueConstraintViolationException) {
                // Two members added the same *new* company at once and we lost the insert: the row
                // lock can't hold a row that doesn't exist yet. The winner exists now, so a fresh
                // attempt finds and attaches to it. Retried out here rather than inside the
                // transaction — a failed statement aborts the surrounding transaction on some
                // drivers, which is the trap the CSV importer avoids the same way.
                //
                // On MySQL that race can instead come back as a *deadlock*, because locking a key
                // that doesn't exist takes a gap lock: `attempts: 3` on the transaction is what
                // covers that one. Either way the loser ends up merging into the winner.
                $result = $this->runCreate($team, $actor, $data, $stageId);
            }

            $teamId = (int) $team->getKey();
            $leadId = (int) $result->lead->getKey();
            $needsEnrichment = $result->lead->enrichment_status === EnrichmentStatus::Pending;

            DB::afterCommit(static function () use ($teamId, $leadId, $needsEnrichment): void {
                // Only a lead that has never been enriched is queued: a company the team already
                // enriched is left alone rather than re-spent on.
                if ($needsEnrichment) {
                    EnrichLeadJob::dispatch($teamId, $leadId);
                }
            });

            return $result;
        });
    }

    /**
     * One transaction: attach or insert, fill the gaps, contact, tags, timeline, note, audit.
     *
     * @param  int|null  $stageId  the board column the new lead lands in, already resolved and
     *                             checked against the team — see {@see self::handle()}.
     */
    private function runCreate(Team $team, User $actor, CreateLeadData $data, ?int $stageId): LeadCreationResult
    {
        return DB::transaction(function () use ($team, $actor, $data, $stageId): LeadCreationResult {
            $values = $data->toValues();
            $fields = OwnLeadFields::companyFields($values);
            $dedupeKey = OwnLeadFields::dedupeKey($values);

            $existing = Lead::query()
                ->where('team_id', $team->getKey())
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            $merged = $existing instanceof Lead;

            $lead = $merged
                ? $this->fillGaps($existing, $fields)
                : $this->insert($team, $data, $fields, $dedupeKey, $stageId);

            $this->addContact($lead, $data);

            if ($data->tags !== []) {
                // Union, not replacement: adding a company the team already had must not drop the
                // tags already on it. The Action matches names case-insensitively, so re-typing a
                // tag that is already there is a no-op rather than a duplicate.
                $this->syncTags->handle($team, $lead, array_values([
                    ...$lead->tags()->pluck('name')->all(),
                    ...$data->tags,
                ]), $actor);
            }

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::Created,
                properties: ['merged' => $merged],
                actor: $actor,
            );

            if ($data->note !== null) {
                $this->createNote->handle($lead, $actor, $data->note);
            }

            $this->audit->log('leads.created', $lead, [
                'merged' => $merged,
                // Whether a contact was recorded, never who: the audit trail has no business
                // holding a second copy of a named individual (CLAUDE.md logging rules).
                'has_contact' => $data->hasContact(),
                'tags' => count($data->tags),
            ], $actor);

            return new LeadCreationResult($lead, $merged);
        }, attempts: 3);
    }

    /**
     * Insert the new lead, attributed to the member rather than to a provider.
     *
     * @param  array<string, string>  $fields
     */
    private function insert(
        Team $team,
        CreateLeadData $data,
        array $fields,
        string $dedupeKey,
        ?int $stageId,
    ): Lead {
        // A lost insert race surfaces as a `UniqueConstraintViolationException` and is retried by
        // `handle()`, not caught here: by then this transaction is already doomed, and on some
        // drivers a failed statement poisons everything after it.
        return Lead::query()->create([
            'team_id' => $team->getKey(),
            // Not a provider: `LeadSource::Manual` says plainly that a member added this
            // themselves, and it ranks with an import — so a later discovery hit fills in what the
            // lead was missing rather than renaming the company they typed.
            'source' => LeadSource::Manual,
            'enrichment_status' => EnrichmentStatus::Pending,
            'company_name' => OwnLeadFields::clamp($data->companyName, 255),
            'pipeline_stage_id' => $stageId,
            'has_personal_data' => $this->isPersonalContact($data),
            // When the member typed it, which is the honest answer to "how fresh is this?".
            'collected_at' => now(),
            'dedupe_key' => $dedupeKey,
            ...$fields,
        ]);
    }

    /**
     * Copy onto an existing lead only the columns it doesn't already hold, and never null one out.
     *
     * No `field_sources` entry is written, matching the CSV importer: provenance per field arrives
     * with enrichment, and a column filled from a form carries the lead's own `manual`/`import`
     * attribution. `has_personal_data` is OR-ed, never cleared — a lead may already hold personal
     * data of its own, and adding a company without a contact can't take that back.
     *
     * @param  array<string, string>  $fields
     */
    private function fillGaps(Lead $lead, array $fields): Lead
    {
        $gaps = [];

        foreach ($fields as $column => $value) {
            if ($lead->getAttribute($column) === null || $lead->getAttribute($column) === '') {
                $gaps[$column] = $value;
            }
        }

        if ($gaps !== []) {
            $lead->forceFill($gaps)->save();
        }

        return $lead;
    }

    /**
     * Record the contact the member typed, if they typed one.
     *
     * Matched on the email where there is one, else on the name, so adding the same person twice
     * doesn't stack up copies. Nothing is marked verified: the address has had no MX check, so it is
     * stored with the member as its source and left for the normal verification to judge (see
     * docs/compliance.md on preferring role-based contacts).
     *
     * Written separately from the CSV importer's contact rather than shared with it, because the two
     * make different claims: a spreadsheet cell says nothing about whether the address belongs to a
     * person or a role inbox, while a member typing one in is recording a person they met.
     */
    private function addContact(Lead $lead, CreateLeadData $data): void
    {
        if (! $data->hasContact()) {
            return;
        }

        $email = $data->contactEmail === null ? null : Str::lower($data->contactEmail);
        $name = $data->contactName;

        $existing = $email !== null
            ? $lead->contacts()->where('email', $email)->first()
            : ($name !== null
                ? $lead->contacts()->whereNull('email')->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first()
                : null);

        if ($existing !== null) {
            return;
        }

        $isRoleBased = $email !== null && $this->emailVerifier->isRoleBased($email);

        $lead->contacts()->create([
            'team_id' => $lead->team_id,
            'name' => $name,
            'title' => $data->contactTitle,
            'email' => $email,
            'phone' => $data->contactPhone === null ? null : OwnLeadFields::clamp($data->contactPhone, 64),
            'is_role_based' => $isRoleBased,
            'is_personal' => $this->isPersonalContact($data),
            'source' => self::CONTACT_SOURCE,
            'method' => self::CONTACT_METHOD,
            'collected_at' => now(),
        ]);

        // A contact added to a lead that didn't have one makes the lead hold personal data. OR-ed
        // against what is already there, so the flag can be raised but never dropped.
        if ($this->isPersonalContact($data) && ! $lead->has_personal_data) {
            $lead->forceFill(['has_personal_data' => true])->save();
        }
    }

    /**
     * Whether what was typed amounts to personal data: a named individual, or any address that
     * isn't a role mailbox. `info@` on its own is a company's front door, not a person — the same
     * distinction {@see EmailVerifier} makes, so the compliance screens read one rule.
     */
    private function isPersonalContact(CreateLeadData $data): bool
    {
        if ($data->contactName !== null) {
            return true;
        }

        if ($data->contactEmail !== null) {
            return ! $this->emailVerifier->isRoleBased(Str::lower($data->contactEmail));
        }

        // A phone number with no name attached is still someone's number.
        return $data->contactPhone !== null;
    }

    /**
     * The board column the lead lands in: the one the member picked, else the team's default.
     *
     * A stage id from another team is ignored rather than refused — it can only arrive from a
     * tampered form, and dropping it files the lead in the default column instead of failing a
     * create the member had every right to make. The attempt is logged, since that is the only
     * trace left that something sent an id it shouldn't have.
     */
    private function stageId(Team $team, CreateLeadData $data): ?int
    {
        if ($data->pipelineStageId !== null) {
            $stage = PipelineStage::query()
                ->where('team_id', $team->getKey())
                ->whereKey($data->pipelineStageId)
                ->first();

            if ($stage instanceof PipelineStage) {
                return (int) $stage->getKey();
            }

            Log::channel('lead_sourcing')->warning('Ignored an out-of-team pipeline stage on a hand-added lead.', [
                'team_id' => (int) $team->getKey(),
                'pipeline_stage_id' => $data->pipelineStageId,
            ]);
        }

        $default = $this->ensureStages->handle($team)->firstWhere('is_default', true);

        return $default instanceof PipelineStage ? (int) $default->getKey() : null;
    }
}
