<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Services\PresetUpdateDiffer;
use Illuminate\Support\Facades\DB;

/**
 * A team takes the library's changes into their own copy of a preset (S15 task 5).
 *
 * **Per field, and only the fields they ticked.** That is the whole shape of the feature and the
 * reason ADR 0018 §3 made a clone a copy rather than a reference: a team that improved their
 * templates and would like the library's new exclusion rules should get the second without losing
 * the first. An all-or-nothing "update" would make the offer unusable for exactly the teams who
 * have been using the type.
 *
 * **Taking nothing is a real answer.** A team can mark the clone level with the preset without
 * taking any field — "I have seen it, I am keeping mine" — which is what stops the panel asking
 * again for ever. `preset_version` is what records the answer, and it is set in every case,
 * including the empty one.
 *
 * **The version bumps.** Taking the library's templates changes what the type looks for, and a
 * signal records which version of a definition classified it, so this is an ordinary definition
 * edit in every respect — and it is audited as one, by the named member who pressed it, which is
 * S12's rule about what accepting a suggestion is.
 *
 * Must be called inside the team's context.
 */
final class AdoptPresetChangesAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly PlatformPresetReader $presets,
        private readonly PresetUpdateDiffer $differ,
    ) {}

    /**
     * @param  list<string>  $fields  which of {@see PresetUpdateDiffer::FIELDS} to take; may be empty
     *
     * @throws SignalsException when the type is not a clone, or is already level with its preset
     */
    public function handle(
        Team $team,
        User $actor,
        SignalDefinition $definition,
        array $fields,
    ): SignalDefinition {
        if ($definition->preset_key === null) {
            // Not a clone of anything. There is no library version to be behind.
            throw SignalsException::presetUnchanged();
        }

        $preset = $this->presets->byKey($definition->preset_key);

        if (! $preset instanceof SignalDefinition || ! $this->differ->isBehind($definition, $preset)) {
            throw SignalsException::presetUnchanged();
        }

        // Narrowed against the **diff**, not against the request: a field the member ticked that
        // has not actually changed is dropped rather than written, so a stale form cannot copy a
        // value over the top of one the team edited in the meantime.
        $changed = array_map(
            static fn (array $change): string => (string) $change['field'],
            $this->differ->changes($definition, $preset),
        );

        $taking = array_values(array_intersect(
            array_values(array_unique($fields)),
            $changed,
        ));

        return $this->teamContext->run($team, fn (): SignalDefinition => DB::transaction(function () use ($actor, $definition, $preset, $taking): SignalDefinition {
            $attributes = [];

            foreach ($taking as $field) {
                if (! in_array($field, PresetUpdateDiffer::FIELDS, strict: true)) {
                    // Belt to the intersection's braces: the field list is a constant and a request
                    // must not be able to name a column outside it.
                    continue;
                }

                $attributes[$field] = $preset->getAttribute($field);
            }

            $definition->forceFill([
                ...$attributes,
                // Set **whatever was taken**, including nothing: the member has answered, and the
                // answer is what stops the offer coming back.
                'preset_version' => $preset->version,
                // Only bumped when something actually changed — acknowledging an offer is not an
                // edit to the type, and bumping would tell the feed that a new classifier version
                // had looked at nothing.
                'version' => $attributes === [] ? $definition->version : $definition->version + 1,
            ])->save();

            $this->audit->log('signals.definition_updated', $definition, [
                'preset_key' => $definition->preset_key,
                'preset_version' => $preset->version,
                // The field **names**, which is the useful half: a team asking "why did my
                // templates change?" gets an answer from the audit log.
                'adopted' => $taking,
            ], $actor);

            return $definition;
        }));
    }
}
