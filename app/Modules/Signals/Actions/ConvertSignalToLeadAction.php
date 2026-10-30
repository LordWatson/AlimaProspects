<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadNoteAction;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns one {@see Signal} into a standard company-level {@see Lead} with the named person as a
 * {@see LeadContact} (S4), after which the lead goes through the existing Enrichment → Scoring →
 * Workspace pipeline like any other. Signals ends at the handover: nothing downstream knows or
 * cares that this lead began as a news article.
 *
 * What the shape of this action is really about:
 *
 * - **Clicking twice must not make two leads.** The signal row is locked for the whole
 *   transaction, and a signal already Converted returns the lead it made rather than erroring — a
 *   double-click, a retried request and an impatient member all land on the same lead.
 * - **A company the team already has is filled in, never duplicated.** The lead is keyed exactly
 *   as discovery keys one ({@see SourcedLead::dedupeKey()}), an existing row with that key is
 *   attached to instead of inserted beside, and {@see LeadDeduplicator} then catches the harder
 *   case where the same company is already on file under a different key. Because
 *   {@see LeadSource::Signal} ranks at the bottom, a merge may only *fill* the survivor's gaps: an
 *   article's spelling of a company never renames the registry record or the team's own import.
 * - **No contact details are invented.** A signal holds a name and a public role and nothing else
 *   (data minimisation), so the contact is written with `email` and `phone` null and the next step
 *   stays LinkedIn or a referral.
 * - **The outreach restriction survives the merge.** `raw.outreach_restriction` is re-asserted on
 *   whichever lead wins, because a compliance limit may be added but never dropped — see
 *   {@see self::stampOrigin()}.
 * - **Nothing external happens inside the transaction.** Enrichment is queued and the event fired
 *   from `afterCommit`, so a rolled-back conversion can't leave a job chasing a lead that no longer
 *   exists.
 *
 * Must be called inside the signal's team context (the controllers and jobs guarantee it).
 */
final class ConvertSignalToLeadAction
{
    public function __construct(
        private readonly LeadDeduplicator $deduplicator,
        private readonly EnsureDefaultPipelineStagesAction $ensureStages,
        private readonly RecordLeadActivityAction $record,
        private readonly CreateLeadNoteAction $createNote,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Convert `$signal` and return the lead it produced — a fresh one, the team's existing record
     * of that company, or (on a repeat) the lead an earlier conversion already made.
     *
     * @throws SignalsException when no company name is available, or when an earlier conversion's
     *                          lead has since been deleted.
     */
    public function handle(Signal $signal, User $actor, ConvertSignalData $data): Lead
    {
        if (trim($data->companyName) === '') {
            // A relocation signal often names a person and no company. Rather than invent a holding
            // entity or file a person as a company, the conversion stops and the form asks.
            throw SignalsException::noSubjectToConvert();
        }

        try {
            [$lead, $attached, $alreadyConverted] = $this->runConversion($signal, $actor, $data);
        } catch (UniqueConstraintViolationException) {
            // Two members converted signals about the same *new* company at once and we lost the
            // insert: the row lock can't hold a row that doesn't exist yet. The winner exists now,
            // so a fresh attempt finds and attaches to it. Retried out here rather than inside the
            // transaction — a failed statement aborts the surrounding transaction on some drivers,
            // which is the trap `ImportLeadsAction` avoids the same way.
            [$lead, $attached, $alreadyConverted] = $this->runConversion($signal, $actor, $data);
        }

        // The caller still holds the pre-conversion model (the action works on its own locked copy),
        // and an S5 controller will redirect using it. Give it the truth.
        $signal->refresh();

        if ($alreadyConverted) {
            return $lead;
        }

        $teamId = (int) $signal->team_id;
        $leadId = (int) $lead->getKey();
        $signalId = (int) $signal->getKey();
        // The de-duplicator promotes the survivor's enrichment state, so this is the state that
        // actually survived the merge rather than the one the new row was created with.
        $needsEnrichment = $lead->enrichment_status === EnrichmentStatus::Pending;

        DB::afterCommit(static function () use ($teamId, $leadId, $signalId, $attached, $needsEnrichment): void {
            // Only a lead that has never been enriched is queued. A new lead always is; a company
            // the team already enriched is left alone rather than re-spent on.
            if ($needsEnrichment) {
                EnrichLeadJob::dispatch($teamId, $leadId);
            }

            SignalConverted::dispatch($teamId, $signalId, $leadId, $attached);
        });

        return $lead;
    }

    /**
     * Run the conversion in one transaction, retrying a deadlock.
     *
     * Three attempts, because this action takes row locks in an order the de-duplicator doesn't
     * know about: it locks the signal, then the lead it attaches to, and only then calls
     * {@see LeadDeduplicator::deduplicate()}, which locks its whole candidate group in id order.
     * Holding one of those rows already means the group is no longer being acquired in id order, so
     * a concurrent enrichment deduping the same companies can deadlock with us. Rare, harmless, and
     * exactly what a retry is for — the second attempt re-reads and finds the work already done.
     *
     * @return array{0: Lead, 1: bool, 2: bool}
     */
    private function runConversion(Signal $signal, User $actor, ConvertSignalData $data): array
    {
        return DB::transaction(fn (): array => $this->convert($signal, $actor, $data), attempts: 3);
    }

    /**
     * The transactional body: lock, attach-or-create, dedupe, contact, timeline, audit, and mark the
     * signal converted.
     *
     * @return array{0: Lead, 1: bool, 2: bool} the lead, whether it was attached to rather than
     *                                          created, and whether this was a repeat conversion
     */
    private function convert(Signal $signal, User $actor, ConvertSignalData $data): array
    {
        /** @var Signal $locked */
        $locked = Signal::query()
            ->whereKey($signal->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status->isConverted()) {
            return [$this->existingLead($locked), true, true];
        }

        $attributes = $this->toSourcedLead($locked, $data)->toLeadAttributes();

        [$lead, $attached] = $this->attachOrCreate($locked, $data, $attributes);

        // Catches the company the team already has under a *different* key — keyed by registry
        // number while this one is keyed by domain, say. The survivor is the most authoritative
        // record, so a signal-sourced row only ever fills its gaps; it may be our own row that is
        // merged away, in which case the survivor is what the signal points at.
        $survivor = $this->deduplicator->deduplicate($lead);
        $attached = $attached || (int) $survivor->getKey() !== (int) $lead->getKey();

        $this->stampOrigin($survivor, $locked, $data);
        $this->addContact($survivor, $data, $this->storedSourceUrl($attributes));

        $this->record->handle(
            lead: $survivor,
            type: LeadActivityType::SignalConverted,
            subject: $locked,
            properties: [
                'signal_id' => (int) $locked->getKey(),
                'event_type' => $locked->event_type->value,
                // The article's own headline, so the timeline says which event this was. A value,
                // not a sentence — the wording around it is composed at render time (EN/AR).
                'headline' => Str::limit($locked->headline, 160),
                'attached' => $attached,
            ],
            actor: $actor,
        );

        if ($data->note !== null) {
            $this->createNote->handle($survivor, $actor, $data->note);
        }

        $this->audit->log('signals.converted', $locked, [
            'lead_id' => (int) $survivor->getKey(),
            'event_type' => $locked->event_type->value,
            'attached' => $attached,
            // Whether a person was recorded, never who: the audit trail has no business holding a
            // second copy of a named individual (CLAUDE.md logging rules).
            'has_contact' => $data->personName !== null,
        ], $actor);

        $locked->forceFill([
            'status' => SignalStatus::Converted,
            // A signal that was dismissed and then converted keeps no stale reason.
            'dismiss_reason' => null,
            'lead_id' => $survivor->getKey(),
            'actioned_by' => $actor->getKey(),
            'actioned_at' => now(),
        ])->save();

        return [$survivor, $attached, false];
    }

    /**
     * The lead an earlier conversion made. A converted signal whose lead has since been deleted has
     * a null `lead_id` (the FK nulls on delete, so Lead Sourcing never depends on Signals) — there
     * is nothing to return and nothing to re-create, so the member is told plainly rather than
     * handed a second lead for a signal that already reads as done.
     */
    private function existingLead(Signal $signal): Lead
    {
        $lead = $signal->lead_id === null
            ? null
            : Lead::query()->whereKey($signal->lead_id)->first();

        if (! $lead instanceof Lead) {
            throw SignalsException::alreadyConverted();
        }

        return $lead;
    }

    /**
     * Normalise the conversion through the same DTO discovery uses, so the lead is keyed, clamped
     * and URL-filtered exactly as a discovered one. Everything the lead knows about its origin goes
     * in `raw`, which is what the detail screen's origin panel reads back.
     */
    private function toSourcedLead(Signal $signal, ConvertSignalData $data): SourcedLead
    {
        return new SourcedLead(
            source: LeadSource::Signal,
            companyName: $data->companyName,
            website: $data->website,
            locality: $data->locality,
            country: $data->country,
            sourceUrl: $signal->source_url,
            raw: $this->originPayload($signal),
        );
    }

    /**
     * What `leads.raw` carries for a converted signal: the one-way pointer back at the signal, the
     * event it was, the headline the member recognised — and the outreach restriction, when the
     * market the signal was found in imposes one.
     *
     * The restriction comes from the **watch's market** ({@see Market::outreachRestriction()}), not
     * from the company's country on the form. That distinction is the whole point: the restriction
     * protects the *person being approached*, who is where the watch was looking, and the company
     * they own may well be registered somewhere else. Keying it on the lead's country would quietly
     * drop the restriction the moment a Dubai founder's lead was filed under a UK holding company —
     * which is exactly the case it exists for.
     *
     * @return array<string, mixed>
     */
    private function originPayload(Signal $signal): array
    {
        $payload = [
            'signal_id' => (int) $signal->getKey(),
            'event_type' => $signal->event_type->value,
            'headline' => Str::limit($signal->headline, 255),
        ];

        $restriction = $signal->watch->market->outreachRestriction();

        if ($restriction !== null) {
            $payload[OutreachRestriction::RAW_KEY] = $restriction->value;
        }

        return $payload;
    }

    /**
     * Attach to the team's existing lead with this identity key, or insert a new one.
     *
     * The lookup is the same one the CSV importer does and for the same reason: `(team_id,
     * dedupe_key)` is unique, so a company already on file has to be found and filled in rather
     * than inserted beside. Only the existing row's **empty** columns are taken from the signal — a
     * converted signal is the weakest source there is, and an article's spelling of a company must
     * never overwrite what a registry or the team's own spreadsheet says.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Lead, 1: bool}
     */
    private function attachOrCreate(Signal $signal, ConvertSignalData $data, array $attributes): array
    {
        $teamId = (int) $signal->team_id;
        $dedupeKey = (string) $attributes['dedupe_key'];

        $existing = $this->lockByDedupeKey($teamId, $dedupeKey);

        if ($existing instanceof Lead) {
            $this->fillGaps($existing, $attributes);

            return [$existing, true];
        }

        // A lost insert race surfaces as a `UniqueConstraintViolationException` and is retried by
        // `handle()`, not caught here: by then this transaction is already doomed, and on some
        // drivers a failed statement poisons everything after it.
        $lead = Lead::query()->create([
            ...$attributes,
            'team_id' => $teamId,
            'enrichment_status' => EnrichmentStatus::Pending,
            'pipeline_stage_id' => $this->stageId($signal, $data),
            'has_personal_data' => $this->namesAPerson($signal, $data),
        ]);

        return [$lead, false];
    }

    /**
     * The team's lead with this identity key, locked for the rest of the transaction. Scoped
     * explicitly by `team_id` beside the global scope, so another team's row can never be attached
     * to however the action was called.
     */
    private function lockByDedupeKey(int $teamId, string $dedupeKey): ?Lead
    {
        return Lead::query()
            ->where('team_id', $teamId)
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Copy only the columns the lead doesn't already hold, and never null one out.
     *
     * Each filled column gets a `field_sources` entry, the same way the de-duplicator's merge
     * stamps one: ADR 0008 asks every field to be able to say where it came from, and a value that
     * arrived from a news article is exactly the kind a reviewer will later want to challenge.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fillGaps(Lead $lead, array $attributes): void
    {
        $gaps = [];
        $sources = $lead->field_sources;
        $sourceUrl = $this->storedSourceUrl($attributes);

        foreach (['company_name', 'website', 'locality', 'country', 'source_url'] as $column) {
            $incoming = $attributes[$column] ?? null;
            $current = $lead->getAttribute($column);

            if (is_string($incoming) && $incoming !== '' && ($current === null || $current === '')) {
                $gaps[$column] = $incoming;
                $sources[$column] = new SourcedValue(
                    source: LeadSource::Signal->value,
                    method: ConvertSignalData::CONTACT_METHOD,
                    sourceUrl: $sourceUrl,
                    collectedAt: Carbon::now(),
                );
            }
        }

        if ($gaps !== []) {
            $lead->field_sources = $sources;
            $lead->forceFill($gaps)->save();
        }
    }

    /**
     * Re-assert the signal's origin and its outreach restriction on whichever lead ended up
     * surviving, merging into that lead's existing `raw` rather than replacing it.
     *
     * This is a deliberate exception to "fill in, never overwrite". `raw` is one of the columns the
     * de-duplicator merges, so a lead that already had a `raw` payload would keep it and silently
     * lose both the `signal_id` the origin panel reads and — far worse — the outreach restriction.
     * A compliance limit may be **added** to a lead but never dropped from one, so it is written
     * last, after every merge has had its say.
     */
    private function stampOrigin(Lead $lead, Signal $signal, ConvertSignalData $data): void
    {
        $raw = $lead->raw ?? [];
        $payload = $this->originPayload($signal);

        // The first signal keeps the pointer. A lead already carrying a `signal_id` was converted
        // from an earlier event, and overwriting the three origin keys would leave the panel
        // describing this event while the team's history says another — so they move as one unit or
        // not at all. (It also stops a provider payload that happens to use `headline` from being
        // replaced by an article's.) The restriction is the exception below, and must not be.
        if (isset($raw['signal_id'])) {
            unset($payload['signal_id'], $payload['event_type'], $payload['headline']);
        }

        // A lead that already carries a restriction keeps it: converting a signal must not downgrade
        // a limit an earlier conversion (or a future module) established.
        if (isset($raw[OutreachRestriction::RAW_KEY]) && ! isset($payload[OutreachRestriction::RAW_KEY])) {
            $payload[OutreachRestriction::RAW_KEY] = $raw[OutreachRestriction::RAW_KEY];
        }

        $lead->forceFill([
            'raw' => [...$raw, ...$payload],
            // OR-ed, never cleared: a lead the team already had may hold personal data of its own,
            // and a conversion that records no contact can't take that back.
            'has_personal_data' => $lead->has_personal_data || $this->namesAPerson($signal, $data),
        ])->save();
    }

    /**
     * Whether the lead ends up holding personal data because of this conversion.
     *
     * True when a contact is recorded — and **also** when the signal names someone the member chose
     * not to record, because the headline stored in `raw` is the article's own words and an article
     * about a founder's exit tends to name the founder. The compliance sweeps and the DSAR hook key
     * off this flag, so it has to answer "is a person in here", not "did we make a contact row".
     */
    private function namesAPerson(Signal $signal, ConvertSignalData $data): bool
    {
        return $data->personName !== null || $signal->subject_person_name !== null;
    }

    /**
     * Record the named person as a contact — name and public role only.
     *
     * `email` and `phone` are null by construction, not by omission: Signals never holds either
     * (ADR 0011), so there is nothing to carry and nothing for a later enrichment to have to
     * un-trust. `is_personal` is true because this is a named individual rather than a role inbox,
     * which is what the compliance screens read.
     *
     * Skipped when the lead already has a contact of that name, so converting a second signal about
     * the same founder doesn't stack up duplicates.
     */
    /**
     * The article URL **as it will actually be stored on the lead**: `SourcedLead` has already
     * dropped a non-http link and clamped it to the column.
     *
     * Everything that cites the article — the lead, its contact, each `field_sources` entry — has
     * to cite that one value. Reading `$signal->source_url` directly instead is what broke this:
     * `signals.source_url` holds up to 2048 characters, and Serper answers some articles with a
     * ~280-character Google redirect, so the contact insert threw `SQLSTATE[22001]` against a
     * 255-wide column while the lead beside it had silently kept a truncated link.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storedSourceUrl(array $attributes): ?string
    {
        $url = $attributes['source_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function addContact(Lead $lead, ConvertSignalData $data, ?string $sourceUrl): void
    {
        if ($data->personName === null) {
            return;
        }

        $exists = $lead->contacts()
            ->whereRaw('LOWER(name) = ?', [Str::lower($data->personName)])
            ->exists();

        if ($exists) {
            return;
        }

        $lead->contacts()->create([
            'team_id' => $lead->team_id,
            'name' => $data->personName,
            'title' => $data->personRole,
            'email' => null,
            'phone' => null,
            'is_role_based' => false,
            'is_personal' => true,
            'source' => ConvertSignalData::CONTACT_SOURCE,
            'source_url' => $sourceUrl,
            'method' => ConvertSignalData::CONTACT_METHOD,
            'collected_at' => now(),
        ]);
    }

    /**
     * The board column the new lead lands in: the one the member picked, else the team's default.
     *
     * A stage id from another team is ignored rather than refused — it can only arrive from a
     * tampered form, and dropping it puts the lead in the default column instead of failing a
     * conversion the member had every right to make. The attempt is logged, since that is the only
     * trace left that something sent an id it shouldn't have.
     */
    private function stageId(Signal $signal, ConvertSignalData $data): ?int
    {
        if ($data->pipelineStageId !== null) {
            $stage = PipelineStage::query()
                ->where('team_id', $signal->team_id)
                ->whereKey($data->pipelineStageId)
                ->first();

            if ($stage instanceof PipelineStage) {
                return (int) $stage->getKey();
            }

            Log::channel('signals')->warning('Ignored an out-of-team pipeline stage on a signal conversion.', [
                'team_id' => (int) $signal->team_id,
                'signal_id' => (int) $signal->getKey(),
                'pipeline_stage_id' => $data->pipelineStageId,
            ]);
        }

        $default = $this->ensureStages->handle($signal->team)->firstWhere('is_default', true);

        return $default instanceof PipelineStage ? (int) $default->getKey() : null;
    }
}
