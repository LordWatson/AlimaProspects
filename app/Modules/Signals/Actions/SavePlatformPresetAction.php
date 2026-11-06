<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create, change or withdraw a platform preset (S15 task 4).
 *
 * **In Signals, called by the admin panel**, which is `docs/modules/admin.md`'s standing rule: the
 * panel owns no business rule, and what a preset *is* belongs here — a `signal_definitions` row with
 * `team_id = null`, unique on `(scope, key)`, versioned, read only through
 * {@see PlatformPresetReader} (ADR 0018 §2). A panel writing the row itself would be a second
 * implementation of all of that, and the second one would be the one that forgot the version.
 *
 * Three things this Action is careful about:
 *
 * - **The version bumps on every change that alters what a preset looks for**, because every clone
 *   records the version it was taken from and that is the only thing that can tell a team their
 *   preset has moved on (task 5). It does **not** bump for a pause: whether a preset is offered is
 *   not a change to the type, and telling thirty teams their clone was out of date because the
 *   library stopped advertising it would be a lie.
 * - **A key never changes.** Clones point at it (`preset_key`), signals record it, and the
 *   catalogue's frozen seed migrations name it. Renaming would orphan all three silently; an
 *   operator who wants a different key withdraws this one and adds another.
 * - **Withdrawing is a soft delete, and clones are untouched.** A team running a copy keeps running
 *   it: they paid for a type, not for a subscription to our opinion of it. The library simply stops
 *   offering it.
 *
 * Runs **outside** any team context — a preset belongs to nobody. Only the panel, behind
 * `PlatformContext` and `AdminAbility::ManageOperations`, may reach it.
 */
final class SavePlatformPresetAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformPresetReader $presets,
    ) {}

    /**
     * @throws SignalsException when the key is taken by another preset
     */
    public function create(User $operator, SignalDefinitionData $data, string $key): SignalDefinition
    {
        $slug = $this->slug($key);

        // `keyIsTaken`, not `byKey`: the unique index does not soft-delete, so a **withdrawn**
        // preset still holds its key. Asking the ordinary read gets "free" and then a 500.
        if ($this->presets->keyIsTaken($slug)) {
            throw SignalsException::presetKeyTaken($slug);
        }

        return DB::transaction(function () use ($operator, $data, $slug): SignalDefinition {
            // **`isPlatformPreset`, not `'team_id' => null`.** `BelongsToTeam` auto-assigns the
            // active team when `team_id` is null, so the literal is not enough on its own: a preset
            // written while any team happened to be active would silently become *that team's* own
            // type — right columns, wrong owner, invisible to every reader. The model's own flag is
            // the mechanism for exactly this (`SignalDefinition::$isPlatformPreset`), the seeder and
            // the factory already use it, and a second door would defeat the point of having one.
            $preset = new SignalDefinition;
            $preset->isPlatformPreset = true;

            $preset->forceFill([
                ...$data->toAttributes(),
                'created_by' => null,
                'key' => $slug,
                // Not a clone of anything: a preset is the original.
                'preset_key' => null,
                'preset_version' => null,
                'version' => 1,
            ])->save();

            $this->audit->log('signals.preset_created', $preset, [
                'preset_key' => $preset->key,
                'templates' => count($preset->query_templates),
            ], $operator);

            return $preset;
        });
    }

    /**
     * Change a preset. Bumps its version, which is what makes the change offerable to every clone.
     */
    public function update(User $operator, SignalDefinition $preset, SignalDefinitionData $data): SignalDefinition
    {
        $this->assertPreset($preset);

        return DB::transaction(function () use ($operator, $preset, $data): SignalDefinition {
            $attributes = $data->toAttributes();

            // The key is the identity every clone, signal and seed migration refers to. Dropped
            // rather than refused, because a form that posts the whole object should not fail on a
            // field it cannot change — the stored value simply wins (the same treatment
            // `SaveSignalWatchlistAction` gives `source_kind`).
            unset($attributes['key']);

            $preset->forceFill([
                ...$attributes,
                'version' => $preset->version + 1,
            ])->save();

            $this->audit->log('signals.preset_updated', $preset, [
                'preset_key' => $preset->key,
                'version' => $preset->version,
            ], $operator);

            return $preset;
        });
    }

    /**
     * Stop offering a preset, or start again.
     *
     * **No version bump.** Whether the library advertises a type is not a change to the type, and
     * bumping would tell every team holding a clone that their copy was out of date when nothing
     * about it had changed.
     */
    public function setActive(User $operator, SignalDefinition $preset, bool $active): SignalDefinition
    {
        $this->assertPreset($preset);

        return DB::transaction(function () use ($operator, $preset, $active): SignalDefinition {
            $preset->forceFill(['is_active' => $active])->save();

            $this->audit->log('signals.preset_updated', $preset, [
                'preset_key' => $preset->key,
                'is_active' => $active,
            ], $operator);

            return $preset;
        });
    }

    /**
     * Withdraw a preset from the library.
     *
     * Soft-deleted, like a team's own type, and for a sharper reason: clones keep `preset_key` and
     * every promoted submission keeps `preset_id`, so a hard delete would orphan both and lose the
     * record of where a team's type came from.
     */
    public function delete(User $operator, SignalDefinition $preset): void
    {
        $this->assertPreset($preset);

        DB::transaction(function () use ($operator, $preset): void {
            // Audited before the delete, so the subject still exists to be named.
            $this->audit->log('signals.preset_deleted', $preset, [
                'preset_key' => $preset->key,
                'version' => $preset->version,
            ], $operator);

            $preset->delete();
        });
    }

    /**
     * Refuse to treat a tenant's row as a preset.
     *
     * {@see PlatformPresetReader} re-asserts `team_id is null` on every read for this reason, and
     * this is the same guard on the write side: an id arriving from a form must never let an
     * operator edit somebody's own type through the library screen.
     *
     * @throws SignalsException
     */
    private function assertPreset(SignalDefinition $preset): void
    {
        if (! $preset->isPreset()) {
            throw SignalsException::definitionNotYours();
        }
    }

    private function slug(string $candidate): string
    {
        return Str::limit(Str::slug(Str::squish($candidate), '_'), 48, '') ?: 'preset';
    }
}
