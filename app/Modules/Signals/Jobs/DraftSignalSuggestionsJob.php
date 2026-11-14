<?php

declare(strict_types=1);

namespace App\Modules\Signals\Jobs;

use App\Modules\Signals\Actions\DraftSignalSuggestionsAction;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Drafts one batch of suggested signal types (Signals v3 P2).
 *
 * One attempt: the drafting call is paid for whether or not the job survives, and a retry would
 * pay again for an answer the member can simply ask for a second time. Unique per batch, so a
 * double dispatch is one draft. It is idempotent besides — a batch that is no longer pending is
 * left alone.
 */
final class DraftSignalSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int */
    public $tries = 1;

    /** @var int Under the queue's 90-second retry window. */
    public $timeout = 85;

    /** @var int */
    public $uniqueFor = 300;

    public function __construct(public readonly int $teamId, public readonly int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(DraftSignalSuggestionsAction $action): void
    {
        $batch = $this->batch();

        if ($batch instanceof SignalSuggestionBatch) {
            $action->handle($batch);
        }
    }

    public function failed(?Throwable $e): void
    {
        // Left pending, the screen would wait for ever on a worker that is not coming back.
        $batch = $this->batch();

        if ($batch instanceof SignalSuggestionBatch && $batch->status === SignalSuggestionStatus::Pending) {
            $batch->forceFill(['status' => SignalSuggestionStatus::Failed, 'error_code' => 'signals.suggestions_failed'])->save();
        }
    }

    private function batch(): ?SignalSuggestionBatch
    {
        // No team context in a worker, so the scope is named rather than assumed.
        return SignalSuggestionBatch::query()
            ->withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->whereKey($this->batchId)
            ->first();
    }
}
