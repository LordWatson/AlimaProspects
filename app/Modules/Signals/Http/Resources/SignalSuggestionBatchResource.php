<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Actions\RequestSignalSuggestionsAction;
use App\Modules\Signals\Data\DraftSignalDefinition;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The latest batch of suggested signal types, for the types screen (Signals v3 P2).
 *
 * Ranked here rather than on the client, because the order is the feature: what a sample search
 * found leads, what is still being checked follows, and what could not be checked or found
 * nothing comes last, marked.
 *
 * @mixin SignalSuggestionBatch
 */
final class SignalSuggestionBatchResource extends JsonResource
{
    /** After this long a suggestion still "testing" is taken to have lost its worker. */
    private const int STALE_TESTING_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalSuggestionBatch $batch */
        $batch = $this->resource;
        $stale = $batch->updated_at?->lt(now()->subMinutes(self::STALE_TESTING_MINUTES)) === true;
        $rows = [];

        foreach ($batch->suggestions ?? [] as $index => $item) {
            $state = (string) ($item['state'] ?? SignalSuggestionBatch::STATE_UNTESTED);

            if ($state === SignalSuggestionBatch::STATE_TESTING && $stale) {
                $state = SignalSuggestionBatch::STATE_UNTESTED;
            }

            $preview = is_array($item['preview'] ?? null) ? $item['preview'] : null;
            $draft = is_array($item['definition'] ?? null)
                ? DraftSignalDefinition::fromAi(['is_viable' => true, 'definition' => $item['definition']])
                : null;

            $rows[] = [
                'index' => $index,
                'title' => (string) ($item['title'] ?? ''),
                'rationale' => (string) ($item['rationale'] ?? ''),
                // A library type the member adds as it ships, or a draft they review first.
                'preset_key' => $item['preset_key'] ?? null,
                'draft' => $draft?->viable === true ? (new SignalDraftResource($draft))->toArray($request) : null,
                'state' => $state,
                'preview' => $preview === null ? null : [
                    'results' => (int) ($preview['results'] ?? 0),
                    'events' => (int) ($preview['events'] ?? 0),
                    'kept' => (int) ($preview['kept'] ?? 0),
                    'diagnosis' => (string) ($preview['diagnosis'] ?? ''),
                ],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [self::rank($b), $b['preview']['kept'] ?? 0, $b['preview']['events'] ?? 0]
            <=> [self::rank($a), $a['preview']['kept'] ?? 0, $a['preview']['events'] ?? 0]);

        return [
            'id' => $batch->getKey(),
            'status' => $batch->status->value,
            'failed' => $batch->status === SignalSuggestionStatus::Failed,
            // Whether the screen should keep asking: the draft is not back, or a sample search is out.
            // A pending batch is waited on for as long as a new request would be handed it back.
            'working' => ($batch->status === SignalSuggestionStatus::Pending
                && $batch->created_at?->gt(now()->subMinutes(RequestSignalSuggestionsAction::STALE_PENDING_MINUTES)) === true)
                || in_array(SignalSuggestionBatch::STATE_TESTING, array_column($rows, 'state'), true),
            'created_at' => $batch->created_at?->toIso8601String(),
            'suggestions' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function rank(array $row): int
    {
        return match (true) {
            $row['state'] === SignalSuggestionBatch::STATE_TESTED && ($row['preview']['kept'] ?? 0) > 0 => 3,
            $row['state'] === SignalSuggestionBatch::STATE_TESTING => 2,
            $row['state'] === SignalSuggestionBatch::STATE_UNTESTED => 1,
            default => 0,
        };
    }
}
