<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalWatchlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One watchlist, as the accounts screen and the API read it (S10).
 *
 * `can_edit_entries` is sent rather than left for a client to derive from `source_kind`: whether a
 * list may be hand-edited is the server's rule (a synced list's entries are an output), and a screen
 * that re-derived it would be a second copy of that rule — the copy that eventually disagrees.
 *
 * @mixin SignalWatchlist
 */
final class SignalWatchlistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalWatchlist $watchlist */
        $watchlist = $this->resource;

        return [
            'id' => $watchlist->id,
            'name' => $watchlist->name,
            'source_kind' => $watchlist->source_kind->value,
            // Translation keys, not finished strings: the client passes them through `t()`.
            'source_kind_label' => $watchlist->source_kind->label(),
            'source_kind_description' => $watchlist->source_kind->description(),
            'is_active' => $watchlist->is_active,
            'is_synced' => $watchlist->source_kind->isSynced(),
            'can_edit_entries' => $watchlist->allowsManualEntries(),
            'entries_count' => $watchlist->entries_count,
            'stages' => $watchlist->syncStages(),
            'owners' => $watchlist->syncOwners(),
            'synced_at' => $watchlist->synced_at?->toIso8601String(),
            // How many watches are pointed at it — what pausing or deleting it would stop. Only
            // when the caller asked for the count; this resource serialises a list.
            'watches_count' => $this->whenCounted('watches'),
            // Resolved here, like every other nested collection in the app: an unresolved
            // collection reaches an Inertia page wrapped in its own `data` key, so the screen
            // would read `entries.data` while the API read `entries`.
            'entries' => $this->whenLoaded(
                'entries',
                fn (): array => SignalWatchlistEntryResource::collection($watchlist->entries)->resolve($request),
            ),
            'created_at' => $watchlist->created_at?->toIso8601String(),
        ];
    }
}
