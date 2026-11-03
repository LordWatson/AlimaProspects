<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Data\DraftSignalDefinition;
use App\Modules\Signals\Enums\SignalSourceKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A composer draft, on its way to a review form (S9).
 *
 * Shaped **exactly like the save payload** rather than like the DTO, so the review form can bind to
 * it directly and post it back unchanged: the field names here are the field names
 * `SaveSignalDefinitionRequest` validates. A client that had to translate between the two would be
 * a third place for a definition's shape to drift.
 *
 * Carries `viable` and `reason` as first-class values, because a refusal is a real answer: "find me
 * rich people in Dubai" is not an event, and the member is owed the reason rather than an empty
 * form.
 *
 * @mixin DraftSignalDefinition
 */
final class SignalDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DraftSignalDefinition $draft */
        $draft = $this->resource;

        $definition = $draft->definition;

        return [
            'viable' => $draft->viable,
            'reason' => $draft->reason,
            'summary' => $draft->summary,
            'definition' => $definition === null ? null : [
                'name' => $definition->name,
                'description' => $definition->description,
                'category' => $definition->category->value,
                'icon' => $definition->icon->value,
                'query_templates' => $definition->queryTemplates,
                'match_keywords' => $definition->matchKeywords,
                'includes' => $definition->includes,
                'excludes' => $definition->excludes,
                'min_confidence' => $definition->minConfidence,
                'min_relevance' => $definition->minRelevance,
                'suggested_approach_hint' => $definition->suggestedApproachHint,
                'markets' => $definition->markets === null
                    ? null
                    : array_map(static fn ($market): string => $market->value, $definition->markets),
                'is_active' => $definition->isActive,
                // **News, always** (S13). The composer drafts what to search for and how to judge
                // it; it does not choose where to spend money. `sourceKinds` is empty on a drafted
                // definition and that resolves to news on save anyway, so sending it explicitly
                // makes the draft a complete form state rather than one the client has to patch.
                'source_keys' => array_map(
                    static fn (SignalSourceKind $kind): string => $kind->value,
                    // Null (the composer never sets them) and empty both become news, so the draft
                    // is a **complete** form state the client can open without patching it.
                    $definition->sourceKinds === null || $definition->sourceKinds === []
                        ? [SignalSourceKind::default()]
                        : $definition->sourceKinds,
                ),
            ],
        ];
    }
}
