<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Actions;

use App\Models\User;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Exceptions\BackgroundCheckException;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\DB;

/**
 * (Re)start the background check for a team. Opens a fresh pending report version so the UI shows
 * "in progress" immediately, then dispatches {@see RunBackgroundCheckJob} `afterCommit` to do the
 * external fetch + AI work. Refuses to stack a second run while one is genuinely in progress, but a
 * pending report left stranded by a dead worker (older than {@see self::STALE_AFTER_MINUTES}) is
 * failed and superseded so the team is never locked out.
 */
final class RerunBackgroundCheckAction
{
    private const int STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, ?User $actor = null): BusinessIntelReport
    {
        return $this->teamContext->run($team, fn (): BusinessIntelReport => DB::transaction(function () use ($team, $actor): BusinessIntelReport {
            $latest = BusinessIntelReport::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            if ($latest instanceof BusinessIntelReport && $latest->status === BackgroundCheckStatus::Pending) {
                // A genuinely in-flight run blocks a second one; a stranded one is reaped.
                if ($latest->created_at !== null && $latest->created_at->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw BackgroundCheckException::alreadyRunning();
                }

                $latest->forceFill([
                    'status' => BackgroundCheckStatus::Failed,
                    'error_message' => 'The background check timed out. Please try again.',
                    'completed_at' => now(),
                ])->save();
            }

            $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

            $report = BusinessIntelReport::query()->create([
                'team_id' => (int) $team->getKey(),
                'version' => ($latest instanceof BusinessIntelReport ? $latest->version : 0) + 1,
                'status' => BackgroundCheckStatus::Pending,
                'business_name' => $profile instanceof BusinessProfile ? $profile->company_name : null,
                'website' => $profile instanceof BusinessProfile ? $profile->website : null,
            ]);

            $this->audit->log('business_intel.rerun', $report, ['version' => $report->version], $actor);

            DB::afterCommit(static function () use ($team, $report): void {
                RunBackgroundCheckJob::dispatch((int) $team->getKey(), (int) $report->getKey());
            });

            return $report;
        }));
    }
}
