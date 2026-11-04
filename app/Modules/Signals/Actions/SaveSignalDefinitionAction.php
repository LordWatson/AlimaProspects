<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Services\SignalDefinitionKeyAllocator;
use App\Modules\Signals\Services\SignalSpendGuard;
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
        private readonly SignalSpendGuard $spend,
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

            // Per-team feed ceiling (S13b). Checked here rather than in the FormRequest because it
            // is a question about the team's other rows, which only an Action can ask — and on both
            // create and update, because adding a feed to an existing type adds a feed.
            $this->assertRoomForFeed($team, $data, $definition);

            // The events API is a plan switch (S16 task 1), and it is checked on create **and**
            // update for the same reason the feed ceiling is: adding the source to an existing type
            // is how a team would otherwise acquire it after a downgrade. The webhook endpoint
            // refuses it too; this is the half that stops the credential ever being minted.
            $this->assertWebhookSourceAllowed($team, $data, $definition);

            if ($definition instanceof SignalDefinition) {
                $definition->forceFill([
                    ...$attributes,
                    'version' => $definition->version + 1,
                ])->save();

                $this->audit->log('signals.definition_updated', $definition, $this->properties($definition, $data), $actor);

                return $definition;
            }

            $this->spend->assertRoomForDefinition($team);

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
     * Refuse a feed once the team already has as many as they are allowed (S13b).
     *
     * Counted over the team's **other** types, so editing a type that already has a feed does not
     * fail because of its own. A type that is not gaining one is not checked at all, which keeps the
     * query off the common path.
     *
     * The ceiling is on existing feeds rather than on how often they are polled, because the two
     * bound different things: the poll floor protects a publisher from us, and this protects us from
     * a team configuring four hundred outbound requests.
     *
     * @throws SignalsException
     */
    private function assertRoomForFeed(Team $team, SignalDefinitionData $data, ?SignalDefinition $definition): void
    {
        $config = $data->sourceConfig;

        // Null means the caller did not mention sources at all, so nothing is being added.
        if ($config === null || ($config['feed']['url'] ?? null) === null) {
            return;
        }

        $max = max(0, (int) config('signals.sources.feed.max_per_team', 10));

        $others = SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->when(
                $definition instanceof SignalDefinition,
                fn ($query) => $query->whereKeyNot($definition->getKey()),
            )
            ->get()
            ->filter(static fn (SignalDefinition $other): bool => ($other->sourceConfig(SignalSourceKind::Feed)['url'] ?? null) !== null)
            ->count();

        if ($others >= $max) {
            throw SignalsException::tooManyFeeds($max);
        }
    }

    /**
     * Refuse a type that runs on the events API when the team's plan does not include it (S16).
     *
     * Only when the source is actually being asked for, so a team without the switch is unaffected
     * by every other save. `sourceKinds === null` means the caller did not mention sources at all,
     * which is the common edit and leaves whatever is stored alone.
     *
     * @throws SignalsException
     */
    private function assertWebhookSourceAllowed(
        Team $team,
        SignalDefinitionData $data,
        ?SignalDefinition $definition,
    ): void {
        if ($data->sourceKinds === null) {
            return;
        }

        if (! in_array(SignalSourceKind::Webhook, $data->sourceKinds, true)) {
            return;
        }

        // **Only when the source is being *added*** (`/code-review`). The edit form re-posts
        // `source_keys` from the stored definition, so refusing any save that mentions the webhook
        // meant a downgraded team could not edit a type they already had — the same trap the S16
        // review removed for playbooks, arriving through a different door. A team keeping what they
        // have is not acquiring anything; a team adding it is.
        if ($definition instanceof SignalDefinition
            && in_array(SignalSourceKind::Webhook, $definition->sourceKinds(), true)) {
            return;
        }

        $this->spend->assertWebhookSourceAllowed($team);
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
