<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Services\SignalDefinitionKeyAllocator;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies platform presets into a team's own signal types (S7, ADR 0018 §3).
 *
 * **A clone, never a reference**, and that is the decision rather than an implementation detail: a
 * definition is a standing instruction to spend money on searches, so a platform edit must not
 * silently change what a team is paying to run, and a team's own tuning must not leak upward. The
 * copy keeps `preset_key` and `preset_version`, which is what lets S15 say "your preset has moved
 * on" and offer the diff instead.
 *
 * **Idempotent, including across a delete.** A team that already has a clone of a preset gets
 * their existing one back — they may have tuned it, and re-adding the same type from the library
 * must not quietly overwrite that. A clone they **deleted** is restored rather than re-created,
 * because `unique(scope_id, key)` knows nothing about `deleted_at`: the soft-deleted row still
 * holds its key, so inserting a second one is a unique violation and a 500. (That was a real bug
 * in the first version of this Action, found in review — the lookup used the default scope and so
 * could not see the row it was about to collide with. `SignalDefinitionKeyAllocator` now holds that
 * knowledge in one place for both this Action and the save.)
 *
 * The quieter collision it also handles: a team can write their own type called "Acquisition",
 * which slugs to `acquisition` — the key the library's preset wants. Both have to be able to exist,
 * so the clone takes a suffixed key when its own is taken by something that is not a clone of it.
 *
 * All of which makes it safe to call from the one-click first watch, which cannot know what the
 * team already has.
 */
final class CloneSignalPresetsAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly PlatformPresetReader $presets,
        private readonly AuditLogger $audit,
        private readonly SignalDefinitionKeyAllocator $keys,
    ) {}

    /**
     * Clone the named presets, returning the team's definitions for them in the order asked.
     *
     * @param  list<string>  $keys
     * @return Collection<int, SignalDefinition>
     *
     * @throws SignalsException when not one of the keys names a preset, or the team is at its
     *                          ceiling.
     */
    public function handle(Team $team, User $actor, array $keys): Collection
    {
        $presets = $this->presets->byKeys($keys);

        if ($presets->isEmpty()) {
            throw SignalsException::unknownPreset();
        }

        return $this->teamContext->run($team, fn (): Collection => DB::transaction(function () use ($team, $actor, $presets): Collection {
            /** @var Collection<int, SignalDefinition> $cloned */
            $cloned = new Collection;

            $teamId = (int) $team->getKey();

            $max = max(1, (int) config('signals.max_definitions_per_team', 25));
            $count = SignalDefinition::query()->where('team_id', $teamId)->count();

            foreach ($presets as $key => $preset) {
                // `withTrashed`, via the allocator, because a deleted clone still holds its key.
                $already = $this->keys->existingClone($teamId, (string) $key);

                if ($already instanceof SignalDefinition) {
                    if ($already->trashed()) {
                        // Restored rather than replaced: the team had this type, removed it, and
                        // has asked for it back. Their tuning comes back with it, which is the same
                        // promise the live-row branch makes.
                        $already->restore();

                        $this->audit->log('signals.definition_restored', $already, [
                            'key' => $already->key,
                            'preset_key' => $preset->key,
                        ], $actor);
                    }

                    $cloned->push($already);

                    continue;
                }

                if ($count >= $max) {
                    throw SignalsException::tooManyDefinitions($max);
                }

                /** @var SignalDefinition $definition */
                $definition = SignalDefinition::query()->create([
                    'team_id' => $teamId,
                    // The preset's key where it is free, because the stored `event_key` on every
                    // signal of this type then reads the same across teams — which is what makes
                    // the cross-tenant admin filter and the platform reporting in S15 possible.
                    // Suffixed where a type the team wrote themselves already took it.
                    'key' => $this->keys->allocate($teamId, $preset->key),
                    'name' => $preset->name,
                    'description' => $preset->description,
                    'category' => $preset->category,
                    'icon' => $preset->icon,
                    'is_active' => true,
                    'query_templates' => $preset->query_templates,
                    'match_keywords' => $preset->match_keywords,
                    'classification_rules' => $preset->classification_rules,
                    'thresholds' => $preset->thresholds,
                    'suggested_approach_hint' => $preset->suggested_approach_hint,
                    'markets' => $preset->markets,
                    'preset_key' => $preset->key,
                    'preset_version' => $preset->version,
                    'version' => 1,
                    'created_by' => $actor->getKey(),
                ]);

                $count++;

                $this->audit->log('signals.definition_cloned', $definition, [
                    'key' => $definition->key,
                    'preset_key' => $preset->key,
                    'preset_version' => $preset->version,
                ], $actor);

                $cloned->push($definition);
            }

            return $cloned;
        }));
    }

    /**
     * The **starter** presets for a market, cloned - what the one-click first watch calls.
     *
     * A team setting Signals up should not have to assemble a library before they can have a watch,
     * and a first watch that finds nothing teaches them the feature doesn't work. So this is the
     * broadest *useful* first watch rather than literally everything: the library grows (S9 takes
     * it to six vertical packs) and cloning all of it would land a brand-new team past
     * `max_definitions_per_team` and paying for a search per template per type on every run.
     * {@see SignalPresetCatalogue::starterKeys()} is the declared subset, and today it is all three
     * v1 types - so nothing about the current behaviour changes.
     *
     * @return Collection<int, SignalDefinition>
     */
    public function handleStartersForMarket(Team $team, User $actor, Market $market): Collection
    {
        $starters = SignalPresetCatalogue::starterKeys();

        /** @var list<string> $keys */
        $keys = array_values($this->presets->active($market)
            ->filter(static fn (SignalDefinition $preset): bool => in_array($preset->key, $starters, strict: true))
            ->map(static fn (SignalDefinition $preset): string => $preset->key)
            ->all());

        if ($keys === []) {
            throw SignalsException::unknownPreset();
        }

        return $this->handle($team, $actor, $keys);
    }
}
