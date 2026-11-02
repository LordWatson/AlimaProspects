<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Services\SignalDefinitionKeyAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one of a team's signal types (S7, ADR 0018), transactionally and audited.
 *
 * Three things worth knowing:
 *
 * - **The key is written once and never again.** Every signal this type has ever classified carries
 *   a copy of the key in `event_key` (ADR 0018 §4), so renaming the type must not orphan its own
 *   history. A rename changes the `name`, which is what anybody actually reads; the key stays the
 *   slug it was born as.
 * - **A team can't create types without limit.** A type is multiplied by every watch that uses it
 *   and — from S8 — by every location those watches cover, so each one is a standing instruction to
 *   spend money on searches. The ceiling is on creating them, exactly as it is for watches.
 * - **`version` is bumped on every save.** Nothing reads it yet; S12 does, when a card has to say
 *   which version of a definition classified it. Bumping it from the start means that answer exists
 *   for signals found before S12 ships, rather than starting at "unknown".
 *
 * A **platform preset is never reachable here**: the global team scope hides it from the controller's
 * read, and {@see SignalDefinitionPolicy} refuses it besides. The
 * guard below is the third of those and exists because the cost of being wrong is a tenant editing
 * every other tenant's library.
 */
final class SaveSignalDefinitionAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SignalDefinitionKeyAllocator $keys,
    ) {}

    public function handle(
        Team $team,
        User $actor,
        SignalDefinitionData $data,
        ?SignalDefinition $definition = null,
    ): SignalDefinition {
        if ($definition instanceof SignalDefinition && $definition->isPreset()) {
            throw SignalsException::presetNotEditable();
        }

        return $this->teamContext->run($team, fn (): SignalDefinition => DB::transaction(function () use ($team, $actor, $data, $definition): SignalDefinition {
            $attributes = $data->toAttributes();

            if ($definition instanceof SignalDefinition) {
                $definition->forceFill([
                    ...$attributes,
                    'version' => $definition->version + 1,
                ])->save();

                $this->audit->log('signals.definition_updated', $definition, $this->properties($definition, $data), $actor);

                return $definition;
            }

            $this->assertRoomForAnother($team);

            /** @var SignalDefinition $created */
            $created = SignalDefinition::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                'key' => $this->keys->allocate((int) $team->getKey(), $data->name),
                'version' => 1,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log('signals.definition_created', $created, $this->properties($created, $data), $actor);

            return $created;
        }));
    }

    /**
     * Refuse a new type once the team is at its ceiling.
     *
     * Create only — an existing type is already counted, so editing one must not fail because the
     * team is at the limit. Soft-deleted rows do not count: they produce no searches.
     *
     * @throws SignalsException
     */
    private function assertRoomForAnother(Team $team): void
    {
        $max = max(1, (int) config('signals.max_definitions_per_team', 25));

        if (SignalDefinition::query()->where('team_id', $team->getKey())->count() >= $max) {
            throw SignalsException::tooManyDefinitions($max);
        }
    }

    /**
     * What the audit row records: the shape of the type and its key, never a second copy of the
     * team's own prose (CLAUDE.md logging rules). The counts are what makes a later "who broke this
     * watch" readable without storing the templates twice.
     *
     * @return array<string, mixed>
     */
    private function properties(SignalDefinition $definition, SignalDefinitionData $data): array
    {
        return [
            'key' => $definition->key,
            'category' => $data->category->value,
            'is_active' => $data->isActive,
            'version' => $definition->version,
            'query_templates_count' => count($data->queryTemplates),
            'match_keywords_count' => count($data->matchKeywords),
            'includes_count' => count($data->includes),
            'excludes_count' => count($data->excludes),
            'min_confidence' => $data->minConfidence,
            'min_relevance' => $data->minRelevance,
            'preset_key' => $definition->preset_key,
        ];
    }
}
