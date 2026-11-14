<?php

declare(strict_types=1);

namespace App\Modules\Signals\Jobs;

use App\Modules\Signals\Actions\TestSignalSuggestionAction;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the sample search for one suggestion of a batch (Signals v3 P2).
 *
 * One job per suggestion, so each stays well inside the queue's retry window and a slow search
 * holds up only its own row. One attempt: the searches are paid for when they are made, and the
 * action records "not tested" rather than throwing for anything it expects. Idempotent — a
 * suggestion that is no longer waiting is left alone.
 */
final class TestSignalSuggestionJob implements ShouldBeUnique, ShouldQueue
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

    public function __construct(
        public readonly int $teamId,
        public readonly int $batchId,
        public readonly int $index,
    ) {}

    public function uniqueId(): string
    {
        return $this->batchId.':'.$this->index;
    }

    public function handle(TestSignalSuggestionAction $action): void
    {
        $batch = $this->batch();

        if ($batch instanceof SignalSuggestionBatch) {
            $action->handle($batch, $this->index);
        }
    }

    public function failed(?Throwable $e): void
    {
        // A worker killed mid-search leaves the row "testing" for ever otherwise.
        DB::transaction(function (): void {
            $batch = $this->batch(lock: true);

            if (! $batch instanceof SignalSuggestionBatch) {
                return;
            }

            $suggestions = $batch->suggestions ?? [];

            if (($suggestions[$this->index]['state'] ?? null) === SignalSuggestionBatch::STATE_TESTING) {
                $suggestions[$this->index]['state'] = SignalSuggestionBatch::STATE_UNTESTED;
                $batch->forceFill(['suggestions' => $suggestions])->save();
            }
        });
    }

    private function batch(bool $lock = false): ?SignalSuggestionBatch
    {
        // No team context in a worker, so the scope is named rather than assumed.
        return SignalSuggestionBatch::query()
            ->withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->whereKey($this->batchId)
            ->first();
    }
}
