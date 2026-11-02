<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account on a watchlist (S10).
 *
 * `lead` is the entry's own answer to "is this already one of ours?" — present when the pipeline
 * sync put it here or the matcher has since tied it to a lead, absent for a name somebody typed
 * about a company they do not have a record for yet. Both are useful states, and the screen shows
 * them differently: one links to the lead, the other says "not a lead yet", which is itself a
 * prompt.
 *
 * The member's own spelling is `name`; `normalised_name` is **not** sent. It is a comparison key,
 * it would read as a mangling of what they typed, and nothing on a screen can do anything with it.
 *
 * @mixin SignalWatchlistEntry
 */
final class SignalWatchlistEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalWatchlistEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'entity_type' => $entry->entity_type->value,
            'entity_type_label' => $entry->entity_type->label(),
            'name' => $entry->name,
            'aliases' => $entry->aliases ?? [],
            'domain' => $entry->domain,
            'is_active' => $entry->is_active,
            'lead_id' => $entry->lead_id,
            'lead' => $this->whenLoaded('lead', fn (): ?array => $entry->lead === null ? null : [
                'id' => $entry->lead->id,
                'company_name' => $entry->lead->company_name,
            ]),
        ];
    }
}
