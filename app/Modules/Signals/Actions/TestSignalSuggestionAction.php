<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\DraftSignalDefinition;
use App\Modules\Signals\Data\SignalPreviewResult;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one suggestion against live search before it is offered (Signals v3 P2).
 *
 * Nobody can tell whether a query template works by reading it (the S0 spike, and every spike
 * since), so a suggestion is put through {@see PreviewSignalDefinitionAction} — the same sample
 * search a member would run by hand, inside the same daily preview budget and on the same
 * `signal_previews` ledger. It stores counts and the preview's own one-line diagnosis, never an
 * article.
 *
 * A suggestion that could not be tested — the budget is spent, the search failed — is kept and
 * marked untested. Refusing to show an idea because we could not afford to check it would hide
 * the cheapest part of the answer.
 */
final class TestSignalSuggestionAction
{
    public function __construct(
        private readonly PreviewSignalDefinitionAction $preview,
        private readonly PlatformPresetReader $presets,
        private readonly TeamContext $teamContext,
    ) {}

    public function handle(SignalSuggestionBatch $batch, int $index): void
    {
        $team = Team::query()->findOrFail($batch->team_id);

        $this->teamContext->run($team, function () use ($batch, $team, $index): void {
            $item = $batch->suggestions[$index] ?? null;

            if (! is_array($item) || ($item['state'] ?? null) !== SignalSuggestionBatch::STATE_TESTING) {
                return;
            }

            $definition = $this->definitionFor($item);
            $result = null;

            if ($definition instanceof SignalDefinition) {
                try {
                    // External work: outside any transaction.
                    $result = $this->preview->handle($team, User::query()->find($batch->requested_by), $definition);
                } catch (DomainException) {
                    // The day's preview allowance is spent, or the plan does not allow it. Expected.
                } catch (Throwable $e) {
                    Log::channel('signals')->warning('A signal suggestion could not be tested.', [
                        'team_id' => $team->getKey(),
                        'signal_suggestion_batch_id' => $batch->getKey(),
                        'index' => $index,
                        'error' => $e::class,
                    ]);
                }
            }

            DB::transaction(function () use ($batch, $index, $result): void {
                // Several tests of one batch finish at once; each rewrites the same JSON column.
                $fresh = SignalSuggestionBatch::query()->lockForUpdate()->whereKey($batch->getKey())->firstOrFail();
                $suggestions = $fresh->suggestions ?? [];

                if (! isset($suggestions[$index])) {
                    return;
                }

                $suggestions[$index] = [
                    ...$suggestions[$index],
                    'state' => $result instanceof SignalPreviewResult ? SignalSuggestionBatch::STATE_TESTED : SignalSuggestionBatch::STATE_UNTESTED,
                    'preview' => $result instanceof SignalPreviewResult ? [
                        'results' => $result->resultCount(),
                        'events' => $result->eventCount(),
                        'kept' => $result->keptCount(),
                        'diagnosis' => $result->diagnosis(),
                    ] : null,
                ];

                $fresh->forceFill([
                    'suggestions' => $suggestions,
                    'cost_micros' => $fresh->cost_micros + ($result instanceof SignalPreviewResult ? $result->costMicros : 0),
                ])->save();
            });
        });
    }

    /**
     * The type to sample: the library's own row for a preset, an unsaved model for a draft.
     *
     * @param  array<string, mixed>  $item
     */
    private function definitionFor(array $item): ?SignalDefinition
    {
        if (is_string($item['preset_key'] ?? null)) {
            return $this->presets->byKey($item['preset_key']);
        }

        if (! is_array($item['definition'] ?? null)) {
            return null;
        }

        $draft = DraftSignalDefinition::fromAi(['is_viable' => true, 'definition' => $item['definition']]);

        return $draft->viable ? $draft->toUnsavedDefinition() : null;
    }
}
