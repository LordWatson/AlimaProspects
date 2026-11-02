<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One signal definition, as the library screen, the watch editor and the API read it (S7, ADR 0018).
 *
 * Serves both a team's own type and a platform preset, and `is_preset` is how a client tells them
 * apart — which it has to, because a preset is read-only until it is cloned. `can_edit` is sent
 * beside it so a screen never has to re-derive the policy's answer from a flag.
 *
 * The team's own words (`name`, `description`, the templates and rules) are **never** translation
 * keys: a team wrote them, in whichever language they wrote them in. A preset's are English the
 * client passes through `t()`, same as every other enum label in this module — which is why
 * `translatable` is on the payload rather than left for a screen to guess.
 *
 * @mixin SignalDefinition
 */
final class SignalDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalDefinition $definition */
        $definition = $this->resource;

        $thresholds = $definition->effectiveThresholds();
        $stored = $definition->thresholds ?? [];

        return [
            'id' => $definition->id,
            'key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'category' => $definition->category->value,
            'category_label' => $definition->category->label(),
            'icon' => $definition->icon->value,
            'is_active' => $definition->is_active,
            'is_preset' => $definition->isPreset(),
            // A preset's wording - and an untouched clone's - is Alima's English and goes through
            // `t()` on the client; a team's own wording is theirs and must not be looked up in a
            // translation file. See `SignalDefinition::usesPlatformWording()`.
            'translatable' => $definition->usesPlatformWording(),
            'query_templates' => $definition->query_templates,
            'match_keywords' => $definition->match_keywords ?? [],
            'includes' => $definition->includes(),
            'excludes' => $definition->excludes(),
            // Both the stored value (null = "use the product's floor", which the form has to be
            // able to show as empty) and the floor that actually applies.
            'min_confidence' => $stored['min_confidence'] ?? null,
            'min_relevance' => $stored['min_relevance'] ?? null,
            'effective_min_confidence' => $thresholds['min_confidence'],
            'effective_min_relevance' => $thresholds['min_relevance'],
            'suggested_approach_hint' => $definition->suggested_approach_hint,
            'markets' => $definition->markets,
            // Provenance of a clone: which preset, at which version. S15 compares the version to
            // the library's and offers the diff; until then it tells a member where a type came
            // from, which is why it is here now rather than later.
            'preset_key' => $definition->preset_key,
            'preset_version' => $definition->preset_version,
            'version' => $definition->version,
            // What deleting it would cost, and whether it is doing anything. Only present when the
            // caller asked for the counts — `whenCounted` rather than a lazy count, because this
            // resource serialises a list.
            'watches_count' => $this->whenCounted('watches'),
            'signals_count' => $this->whenCounted('signals'),
            'created_at' => $definition->created_at?->toIso8601String(),
        ];
    }
}
