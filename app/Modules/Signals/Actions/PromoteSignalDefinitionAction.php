<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An operator answers a library submission (S15 task 4).
 *
 * **This Action lives in Signals and the admin panel calls it**, which is `docs/modules/admin.md`'s
 * standing rule: the panel owns no business rule, and what a platform preset is — a
 * `signal_definitions` row with `team_id = null`, versioned, keyed uniquely within the platform
 * scope — is Signals' rule. A panel that wrote the row itself would be a second implementation of
 * ADR 0018 §2.
 *
 * **Promotion copies; it does not move.** The team's own type is untouched: same row, same name,
 * same history, still running. A new platform preset is created from its fields with its own key.
 * The alternative — re-pointing the team's row at `team_id = null` — would take their type away
 * from them as a reward for sharing it.
 *
 * **A promotion never touches a clone.** Teams who already hold a copy of a preset keep running
 * exactly what they were running; they are *offered* the change by S15 task 5 and may take it per
 * field. That is the whole reason ADR 0018 §3 made a clone a copy rather than a reference, and
 * `version` is what makes the offer possible.
 *
 * Runs **outside** any team context — a preset belongs to nobody, and these writes are deliberately
 * cross-tenant, which is why only the panel (behind `PlatformContext`) may reach them.
 */
final class PromoteSignalDefinitionAction
{
    /** Longest review note. A sentence to the team, not a report. */
    public const int MAX_NOTE = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformPresetReader $presets,
    ) {}

    /**
     * Accept a submission into the library, returning the preset it produced.
     *
     * @throws SignalsException when the submission has been answered, or its type has gone
     */
    public function promote(
        SignalDefinitionSubmission $submission,
        User $operator,
        ?string $note = null,
        ?string $key = null,
    ): SignalDefinition {
        return DB::transaction(function () use ($submission, $operator, $note, $key): SignalDefinition {
            /** @var SignalDefinitionSubmission $locked */
            $locked = SignalDefinitionSubmission::query()
                ->withoutGlobalScopes()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw SignalsException::submissionSettled();
            }

            // **Read without the team scope, explicitly.** The `definition` relation is
            // team-scoped like every other read of a tenant row, so a promote running with any
            // team in context would find nothing and report the submission as out of date. The
            // panel's `PlatformContext` drops the context on every admin request, which makes that
            // true today and dependent on its caller; naming the cross-tenant read here is what
            // makes it true at all. Deliberately cross-tenant, like everything else this Action
            // touches.
            $source = SignalDefinition::query()
                ->withoutGlobalScope('team')
                ->withTrashed()
                ->find($locked->signal_definition_id);

            if (! $source instanceof SignalDefinition || $source->trashed()) {
                // The team deleted the type while the submission waited. Nothing to promote, and
                // the honest answer is to settle the row rather than leave it in a queue for ever.
                throw SignalsException::tuningSuggestionStale();
            }

            // **A named key is taken at face value; a derived one is never allowed to collide.**
            // The difference matters: an operator typing `uk_acquisition` means "fold this into
            // that preset", and a submission whose own key happens to match an existing preset
            // means nothing of the sort — silently overwriting the library because two teams chose
            // the same obvious name is the one outcome this screen must not have.
            $presetKey = $key !== null
                ? $this->slug($key)
                : $this->freeKey($this->slug($source->key));
            // **`withTrashed`, because a withdrawn preset still holds its key.** The unique index
            // does not soft-delete, so `byKey` would answer null for one and the insert would then
            // fail on the index with a 500. Folding into it is also the right answer rather than a
            // refusal: naming a key means "this *is* that preset", and an operator naming a
            // withdrawn one plainly wants it back carrying the new content — so it is restored and
            // offered again, which {@see self::updatePreset()} does.
            $existing = SignalDefinition::query()
                ->withoutGlobalScope('team')
                ->withTrashed()
                ->whereNull('team_id')
                ->where('scope_id', SignalDefinition::PLATFORM_SCOPE)
                ->where('key', $presetKey)
                ->first();

            // An existing preset with this key is **updated and its version bumped**, which is what
            // makes a submission a way of improving the library rather than only of adding to it —
            // and the bump is what lets every team holding a clone be offered the change.
            $preset = $existing instanceof SignalDefinition
                ? $this->updatePreset($existing, $source)
                : $this->createPreset($presetKey, $source);

            $locked->forceFill([
                'status' => SignalSubmissionStatus::Promoted,
                'review_note' => $this->note($note),
                'preset_id' => $preset->getKey(),
                'reviewed_by' => $operator->getKey(),
                'reviewed_at' => now(),
            ])->save();

            // Audited against the **preset**, because that is the row the platform changed. The
            // team's own type is untouched and has nothing to record.
            $this->audit->log('signals.preset_promoted', $preset, [
                'submission_id' => (int) $locked->getKey(),
                'preset_key' => $preset->key,
                'version' => $preset->version,
                'from_definition_id' => (int) $source->getKey(),
            ], $operator);

            return $preset;
        });
    }

    /**
     * Turn a submission down, with a note the team will read.
     *
     * The note is not optional in spirit even though it is in type: a rejection with no reason is
     * the platform taking somebody's contribution and saying nothing, and the panel's form requires
     * one. Enforcing it here as well would stop a future caller from recording a rejection it
     * genuinely has no words for — a type whose team has since vanished, say.
     */
    public function reject(
        SignalDefinitionSubmission $submission,
        User $operator,
        ?string $note = null,
    ): SignalDefinitionSubmission {
        return DB::transaction(function () use ($submission, $operator, $note): SignalDefinitionSubmission {
            /** @var SignalDefinitionSubmission $locked */
            $locked = SignalDefinitionSubmission::query()
                ->withoutGlobalScopes()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw SignalsException::submissionSettled();
            }

            $locked->forceFill([
                'status' => SignalSubmissionStatus::Rejected,
                'review_note' => $this->note($note),
                'reviewed_by' => $operator->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log('signals.preset_submission_rejected', $locked, [
                'signal_definition_id' => (int) $locked->signal_definition_id,
                'has_note' => $locked->review_note !== null,
            ], $operator);

            return $locked;
        });
    }

    /**
     * A new preset from a team's type.
     *
     * **`team_id` and `created_by` are null and `preset_key` is null**: the row belongs to the
     * platform and is nobody's clone. `preset_version` likewise — a preset is not a copy of
     * anything, and carrying the source's would make it look like one.
     */
    private function createPreset(string $key, SignalDefinition $source): SignalDefinition
    {
        // **`isPlatformPreset`, not `'team_id' => null`.** `BelongsToTeam` fills a null `team_id`
        // from the ambient context on create, so a preset written while the submitting team
        // happened to be active would silently become *their* own type — the right columns, the
        // wrong owner, and a library that never shows it. The model carries a flag for exactly
        // this case and the seeder and factory already use it.
        $preset = new SignalDefinition;
        $preset->isPlatformPreset = true;

        $preset->forceFill([
            ...$this->fieldsFrom($source),
            'created_by' => null,
            'key' => $key,
            'preset_key' => null,
            'preset_version' => null,
            'is_active' => true,
            'version' => 1,
        ])->save();

        return $preset;
    }

    /**
     * Fold a team's type into an existing preset and bump its version.
     *
     * The bump is the point: `signal_definitions.preset_version` on every clone records which
     * version it was taken from, so this is what makes those clones show "your preset has moved on"
     * (task 5). Nothing about a clone changes here — they are offered the diff and take it per
     * field, which is ADR 0018 §3's whole argument.
     */
    private function updatePreset(SignalDefinition $preset, SignalDefinition $source): SignalDefinition
    {
        $preset->forceFill([
            ...$this->fieldsFrom($source),
            'version' => $preset->version + 1,
            // A withdrawn preset named by key is being brought back deliberately — see the
            // `withTrashed` note at the call site. Restored and offered again, because a key that
            // nothing offers is not a key an operator would have typed.
            'deleted_at' => null,
            'is_active' => true,
        ])->save();

        return $preset;
    }

    /**
     * The fields a preset takes from a team's type.
     *
     * Named explicitly rather than copied wholesale, and the omissions are the interesting part:
     * nothing about **this team** travels (`team_id`, `created_by`, `preset_key`,
     * `preset_version`), and neither does anything about **their configuration of a source**
     * (`source_config` holds a feed URL and a webhook's identity — one tenant's integration, not a
     * template anybody else could run).
     *
     * @return array<string, mixed>
     */
    private function fieldsFrom(SignalDefinition $source): array
    {
        return [
            'name' => $source->name,
            'description' => $source->description,
            'category' => $source->category,
            'icon' => $source->icon,
            'query_templates' => $source->query_templates,
            'query_templates_i18n' => $source->query_templates_i18n,
            // **These three were missing and the omission was a real loss** (review): they are in
            // `PresetUpdateDiffer::FIELDS`, so a clone may take them from a preset — and a
            // promotion that dropped them threw away the half of a team's work that does the most
            // (`classification_rules`' excludes are what stop a funding tracker becoming a signal),
            // then showed every other clone a diff against a preset the submission had not
            // actually changed. **The two lists have to agree**: anything a clone may take is
            // something a promotion must carry.
            'match_keywords' => $source->match_keywords,
            'classification_rules' => $source->classification_rules,
            'suggested_approach_hint' => $source->suggested_approach_hint,
            'markets' => $source->markets,
            'thresholds' => $source->thresholds,
            'source_keys' => $source->source_keys,
        ];
    }

    /**
     * A preset key in the shape every other one has: lower case, underscores, bounded
     * (`uk_acquisition`).
     */
    private function slug(string $candidate): string
    {
        return Str::limit(Str::slug(Str::squish($candidate), '_'), 48, '') ?: 'submitted_type';
    }

    /**
     * The first key in this family that no preset holds.
     *
     * Bounded, because an unbounded loop over a unique index is a way to hang a request: after
     * twenty tries the key carries the submission's own id, which is unique by construction.
     */
    private function freeKey(string $base): string
    {
        // `keyIsTaken`, not `byKey`: the unique index does not soft-delete, so a **withdrawn**
        // preset still holds its key and a derived key that stepped over it would 500.
        if (! $this->presets->keyIsTaken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 20; $suffix++) {
            $candidate = $base.'_'.$suffix;

            if (! $this->presets->keyIsTaken($candidate)) {
                return $candidate;
            }
        }

        return $base.'_'.uniqid();
    }

    private function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = Str::limit(Str::squish($note), self::MAX_NOTE, '');

        return $trimmed === '' ? null : $trimmed;
    }
}
