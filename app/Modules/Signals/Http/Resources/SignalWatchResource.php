<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One watch, as the settings screen and the API read it.
 *
 * The watched types come down as full {@see SignalDefinitionResource}s rather than bare ids: the
 * settings screen renders a checkbox per type with its one-line explainer, and the API shouldn't
 * make a client fetch the library to render a watch. Since S7 they are rows reached through a pivot
 * (ADR 0018 §5), so they are a `whenLoaded` relation — a watch serialised without them says nothing
 * about its types rather than lying with an empty list.
 *
 * `next_due_at` is computed from the cadence rather than stored, so there is nothing to keep in
 * sync.
 *
 * @mixin SignalWatch
 */
final class SignalWatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalWatch $watch */
        $watch = $this->resource;

        return [
            'id' => $watch->id,
            'name' => $watch->name,
            // The team's declared compliance regime for this watch, which since S8 is a
            // backstop rather than the thing that picks where to look (ADR 0019).
            'market' => $watch->market->value,
            'market_label' => $watch->market->label(),
            'locations' => $watch->locations ?? [],
            // Sent with their labels and their **approach note**, because choosing where to look is
            // also choosing how the resulting leads may be approached and nothing else on the
            // screen says so.
            'regions' => array_map(static fn (SearchRegion $region): array => [
                'value' => $region->value,
                'label' => $region->label(),
                'approach_note' => $region->approachNote(),
            ], $watch->searchRegions()),
            'definitions' => $this->whenLoaded(
                'definitions',
                fn (): array => SignalDefinitionResource::collection($watch->definitions)->resolve($request),
            ),
            // The team's own exclusion list (S6) — their competitors, which only they can know.
            'ignore_domains' => $watch->ignore_domains ?? [],
            // Which accounts this watch follows instead of places (S10). The id is what the form
            // posts back; the name is so the card can say "following My pipeline" without the
            // client holding the whole list of watchlists.
            'signal_watchlist_id' => $watch->signal_watchlist_id,
            'watchlist_name' => $watch->relationLoaded('watchlist') ? $watch->watchlist?->name : null,
            'cadence' => $watch->cadence->value,
            'cadence_label' => $watch->cadence->label(),
            'is_active' => $watch->is_active,
            'last_run_at' => $watch->last_run_at?->toIso8601String(),
            // When the hourly scheduler will next consider it. Null for a watch that has never run
            // (it is due now) or one that is paused (it isn't due at all).
            'next_due_at' => $this->nextDueAt($watch),
            'icp' => $this->whenLoaded('icp', fn (): ?array => $watch->icp === null ? null : [
                'id' => $watch->icp->id,
                'name' => $watch->icp->name,
            ]),
            'signals_count' => $this->whenCounted('signals'),
            // Resolved to a plain list rather than handed over as a resource collection: a nested
            // collection keeps its own `data` wrapper, which would leave the client reading
            // `watch.runs.data` here and `watch.runs` elsewhere.
            'runs' => $this->whenLoaded(
                'runs',
                fn (): array => SignalRunResource::collection($watch->runs)->resolve($request),
            ),
            'created_at' => $watch->created_at?->toIso8601String(),
        ];
    }

    private function nextDueAt(SignalWatch $watch): ?string
    {
        if (! $watch->is_active || $watch->last_run_at === null) {
            return null;
        }

        return $watch->last_run_at
            ->addHours($watch->cadence->staleAfterHours())
            ->toIso8601String();
    }
}
