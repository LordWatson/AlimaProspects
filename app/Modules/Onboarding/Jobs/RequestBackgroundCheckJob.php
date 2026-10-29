<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Jobs;

use App\Modules\Onboarding\Actions\CompleteOnboardingAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trigger the AI background check for a team once onboarding completes.
 *
 * STUB (Phase 3). The real work — fetching the business's public web presence and running the
 * `business_intel.background_check` prompt — lands in Phase 6 (Business Intelligence), which will
 * flesh out {@see self::handle()}. Dispatched `afterCommit` by
 * {@see CompleteOnboardingAction}. Kept idempotent and unique per
 * team so a double-complete can't queue two checks.
 */
final class RequestBackgroundCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * Release the uniqueness lock after an hour so a crashed worker can't block the next request.
     *
     * @var int
     */
    public $uniqueFor = 3600;

    /**
     * @var int
     */
    public $timeout = 60;

    /**
     * @var list<int>
     */
    public $backoff = [10, 30, 60];

    public function __construct(public readonly int $teamId) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(): void
    {
        // Phase 6 replaces this with the real Business Intelligence run. For now we record that a
        // check was requested so the trigger is observable end-to-end.
        Log::channel('ai')->info('Background check requested (stub — implemented in Phase 6).', [
            'team_id' => $this->teamId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('ai')->error('Background check request failed.', [
            'team_id' => $this->teamId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
