<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tuning suggestion, as the settings panel reads it (S12).
 *
 * **The evidence travels with the proposal**, and that is the whole reason this resource is not
 * three fields. A suggestion a member cannot check is one they can only obey or ignore — so the
 * panel shows the counts the advisor reached its opinion from, in their own units, beside the
 * change it is proposing. "12 of the last 15 you dismissed from this outlet" is an argument; "we
 * recommend excluding this source" is an instruction.
 *
 * Labels are **translation keys** passed through `t()` on the client, like every other enum label
 * leaving this module — the screens are EN/AR (ADR 0007). The one exception is the same one the
 * feed card makes: a definition's **name** may be the team's own words in their own language, so it
 * is sent as typed with a flag saying so.
 *
 * `evidence` is sent as the raw counts rather than as a finished sentence for the same reason:
 * composing "12 of 15" into English here would make the Arabic panel read in English, and the
 * numbers are what a member is actually weighing.
 *
 * @mixin SignalTuningSuggestion
 */
final class SignalTuningSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalTuningSuggestion $suggestion */
        $suggestion = $this->resource;

        $definition = $suggestion->relationLoaded('definition') ? $suggestion->definition : null;

        return [
            'id' => $suggestion->id,
            'kind' => $suggestion->kind->value,
            'kind_label' => $suggestion->kind->label(),
            'kind_explanation' => $suggestion->kind->explanation(),
            'status' => $suggestion->status->value,
            'status_label' => $suggestion->status->label(),
            // The change itself, as values. The panel renders them; it does not have to parse a
            // sentence to know what accepting would do.
            'payload' => $suggestion->payload,
            // What the advisor counted. Raw numbers on purpose — see the class docblock.
            'evidence' => $suggestion->evidence,
            // Which type this is about, when it is about one. Null for a proposal about a source
            // across every type, which the panel reads as "this applies to all your watches".
            'definition' => $definition instanceof SignalDefinition ? [
                'id' => $definition->id,
                'name' => $definition->name,
                // The same rule the feed card follows: Alima's wording is translated, a team's own
                // words are rendered as typed.
                'name_translatable' => $definition->usesPlatformWording(),
                // A proposal about a type the team has since deleted is still readable, which is
                // what lets the panel say so instead of showing a blank row.
                'deleted' => $definition->trashed(),
            ] : null,
            'created_at' => $suggestion->created_at?->toIso8601String(),
        ];
    }
}
