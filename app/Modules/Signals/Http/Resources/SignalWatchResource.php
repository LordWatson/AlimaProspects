<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One watch, as the settings screen and the API read it.
 *
 * The event types are sent **with their labels and descriptions**, not as bare keys: the settings
 * screen renders a checkbox per type with its one-line explainer, and the API shouldn't make a
 * client hold its own copy of that wording. `next_due_at` is computed from the cadence rather than
 * stored, so there is nothing to keep in sync.
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
            'market' => $watch->market->value,
            'market_label' => $watch->market->label(),
            'location' => $watch->location,
            'event_types' => $this->eventTypes($watch),
            // The team's own exclusion list (S6) — their competitors, which only they can know.
            'ignore_domains' => $watch->ignore_domains ?? [],
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

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function eventTypes(SignalWatch $watch): array
    {
        $types = [];

        foreach ($watch->event_types as $value) {
            $type = SignalEventType::tryFrom((string) $value);

            // A type dropped from the enum since the watch was saved is skipped rather than
            // crashing the screen; the watch simply stops watching for it.
            if ($type !== null) {
                $types[] = [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                ];
            }
        }

        return $types;
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
