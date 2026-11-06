<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Resources;

use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Data\DraftIcp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A composer draft, on its way to the editor.
 *
 * Shaped **exactly like the save payload** rather than like the DTO, so the editor can bind to it
 * and post it back unchanged: the field names here are the field names `SaveIcpRequest` validates.
 * A client that had to translate between the two would be a third place for a profile's shape to
 * drift.
 *
 * `is_active` is deliberately absent. The composer drafts *who to look for*; whether this becomes
 * the profile the whole account is graded against is the member's decision and theirs alone, so the
 * editor's own tick box keeps whatever it was set to.
 *
 * @mixin DraftIcp
 */
final class IcpDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DraftIcp $draft */
        $draft = $this->resource;

        $profile = $draft->profile;

        return [
            'viable' => $draft->viable,
            'reason' => $draft->reason,
            'summary' => $draft->summary,
            'profile' => $profile === null ? null : [
                'name' => $profile->name,
                'description' => $profile->description,
                'criteria' => array_map(static fn (CriterionData $criterion): array => [
                    'type' => $criterion->type->value,
                    'value' => $criterion->value,
                    'weight' => $criterion->weight,
                ], $profile->criteria),
            ],
        ];
    }
}
