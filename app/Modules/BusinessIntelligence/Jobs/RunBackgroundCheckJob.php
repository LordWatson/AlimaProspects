<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Jobs;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\BusinessIntelligence\Contracts\WebContentFetcher;
use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Events\BackgroundCheckProgressed;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the AI background check for a team (Phase 6). It resolves the report to fulfil (an explicit
 * pending report from a re-run, or — on the onboarding trigger — the team's pending report or a
 * fresh version), fetches the business's public web text via the {@see WebContentFetcher}, runs the
 * `business_intel.background_check` prompt through the {@see AiClient}, validates + stores the
 * structured findings, and broadcasts progress on the team's private channel.
 *
 * - Idempotent + `ShouldBeUnique` per team, so a double trigger can't queue two concurrent checks.
 *   A retry that finds its report already terminal (or missing) no-ops rather than re-spending.
 * - External work (web fetch + AI) runs OUTSIDE any DB transaction (CLAUDE.md external-call rule).
 * - Fetched web content is wrapped in {@see UntrustedContent} — treated as data, never instructions.
 * - Credit exhaustion and malformed output are terminal (mark Failed, no retry); other errors
 *   bubble so the queue retries, and {@see self::failed()} marks the report Failed on final failure.
 */
final class RunBackgroundCheckJob implements ShouldBeUnique, ShouldQueue
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
     * Release the uniqueness lock after ten minutes so a crashed worker can't block the next run.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long run can't be dispatched twice.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 90, 180];

    public function __construct(public readonly int $teamId, public readonly ?int $reportId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(AiClient $ai, WebContentFetcher $fetcher, TeamContext $teamContext): void
    {
        $teamContext->run($this->teamId, function () use ($ai, $fetcher): void {
            $report = $this->resolveReport();

            // Nothing to do: the report was already completed (idempotent retry) or no longer exists.
            if (! $report instanceof BusinessIntelReport) {
                return;
            }

            $profile = BusinessProfile::query()->where('team_id', $this->teamId)->first();
            $hasProfile = $profile instanceof BusinessProfile;

            $this->broadcast($report->status, 10, 'Starting your background check', $report->version);

            // --- External work: fetch public web text (outside any transaction) ---
            $website = $hasProfile ? $profile->website : null;
            $webText = $fetcher->fetch($website);
            $this->broadcast(BackgroundCheckStatus::Pending, 45, 'Reading your public web presence', $report->version);

            // --- External work: run the AI background-check prompt ---
            try {
                $response = $ai->structured('business_intel.background_check', [
                    'business_name' => $hasProfile ? ($profile->company_name ?? '') : '',
                    'website' => $website ?? '',
                    // Scraped content is untrusted — wrapped so embedded instructions can't execute.
                    'web_content' => UntrustedContent::from($webText),
                ]);
            } catch (AiCreditExceededException|InvalidAiResponseException $e) {
                // Terminal: retrying won't help (no credit / model can't produce valid output).
                $this->markFailed($report, $e);

                return;
            }

            $findings = BackgroundCheckFindings::fromAi($response->data);

            DB::transaction(function () use ($report, $findings, $response): void {
                $report->forceFill([
                    'status' => BackgroundCheckStatus::Ready,
                    'findings' => $findings->toStorage(),
                    'prompt_key' => $response->promptKey,
                    'prompt_version' => $response->promptVersion,
                    'model' => $response->model,
                    'cost_micros' => $response->costMicros,
                    'error_message' => null,
                    'completed_at' => now(),
                ])->save();
            });

            Log::channel('ai')->info('Background check completed.', [
                'team_id' => $this->teamId,
                'version' => $report->version,
                'prompt_version' => $response->promptVersion,
                'cost_micros' => $response->costMicros,
                'cache_hit' => $response->cacheHit,
            ]);

            $this->broadcast(BackgroundCheckStatus::Ready, 100, 'Your background check is ready', $report->version);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('ai')->error('Background check job failed.', [
            'team_id' => $this->teamId,
            'exception' => $exception->getMessage(),
        ]);

        app(TeamContext::class)->run($this->teamId, function () use ($exception): void {
            $report = BusinessIntelReport::query()
                ->where('team_id', $this->teamId)
                ->where('status', BackgroundCheckStatus::Pending)
                ->orderByDesc('version')
                ->first();

            if ($report instanceof BusinessIntelReport) {
                $this->markFailed($report, $exception);
            }
        });
    }

    /**
     * Decide which report this run fulfils. For an explicit `reportId` (a re-run), use that row and
     * no-op if it's already terminal (a retry after the save succeeded) or gone. Otherwise (the
     * onboarding trigger) reuse the team's pending report, or open the next version — unless this is
     * a retry that finds the latest already completed, in which case there's nothing to do.
     */
    private function resolveReport(): ?BusinessIntelReport
    {
        return DB::transaction(function (): ?BusinessIntelReport {
            if ($this->reportId !== null) {
                $report = BusinessIntelReport::query()
                    ->where('team_id', $this->teamId)
                    ->lockForUpdate()
                    ->find($this->reportId);

                if (! $report instanceof BusinessIntelReport || $report->status->isTerminal()) {
                    return null;
                }

                return $report;
            }

            $latest = BusinessIntelReport::query()
                ->where('team_id', $this->teamId)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            if ($latest instanceof BusinessIntelReport && $latest->status === BackgroundCheckStatus::Pending) {
                return $latest;
            }

            // A retry that finds the latest already completed means a prior attempt finished — don't
            // re-run and re-spend.
            $isRetry = ($this->job?->attempts() ?? 1) > 1;
            if ($latest instanceof BusinessIntelReport && $latest->status->isTerminal() && $isRetry) {
                return null;
            }

            $profile = BusinessProfile::query()->where('team_id', $this->teamId)->first();

            return BusinessIntelReport::query()->create([
                'team_id' => $this->teamId,
                'version' => ($latest instanceof BusinessIntelReport ? $latest->version : 0) + 1,
                'status' => BackgroundCheckStatus::Pending,
                'business_name' => $profile instanceof BusinessProfile ? $profile->company_name : null,
                'website' => $profile instanceof BusinessProfile ? $profile->website : null,
            ]);
        });
    }

    private function markFailed(BusinessIntelReport $report, Throwable $exception): void
    {
        DB::transaction(function () use ($report, $exception): void {
            $report->forceFill([
                'status' => BackgroundCheckStatus::Failed,
                // A friendly, non-sensitive message for the UI; full detail is in the logs.
                'error_message' => $this->friendlyError($exception),
                'completed_at' => now(),
            ])->save();
        });

        $this->broadcast(BackgroundCheckStatus::Failed, 100, 'We couldn’t complete the background check', $report->version);
    }

    private function friendlyError(Throwable $exception): string
    {
        return $exception instanceof AiCreditExceededException
            ? 'You’ve reached your AI usage allowance. Try again after it resets or upgrade your plan.'
            : 'We couldn’t complete the background check. Please try again.';
    }

    private function broadcast(BackgroundCheckStatus $status, int $percent, string $step, ?int $version): void
    {
        event(new BackgroundCheckProgressed($this->teamId, $status, $percent, $step, $version));
    }
}
