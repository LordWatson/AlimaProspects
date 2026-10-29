<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Actions;

use App\Models\User;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Exceptions\OnboardingException;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use Illuminate\Support\Facades\DB;

/**
 * Finish onboarding for a team: stamp the Business Profile and progress as complete, apply the
 * chosen primary market as the team's compliance default, and trigger the AI background check.
 *
 * Guards that every data step has been saved first (throws {@see OnboardingException} otherwise).
 * The background-check job is dispatched `afterCommit` — never inside the transaction — per the
 * CLAUDE.md external-work rule. Re-running bumps the profile version (re-completable).
 */
final class CompleteOnboardingAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, ?User $actor = null): BusinessProfile
    {
        return $this->teamContext->run($team, fn (): BusinessProfile => DB::transaction(function () use ($team, $actor): BusinessProfile {
            // Read + guard + write in one transaction, locking the rows, so two concurrent
            // completions can't both pass the guard or double-bump the version.
            $progress = OnboardingProgress::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first() ?? new OnboardingProgress(['team_id' => $team->getKey()]);

            $missing = array_values(array_diff(
                OnboardingStep::dataValues(),
                $progress->completed_steps ?? [],
            ));

            if ($missing !== []) {
                throw OnboardingException::incomplete($missing);
            }

            $profile = BusinessProfile::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $profile->forceFill([
                'version' => $profile->isComplete() ? $profile->version + 1 : $profile->version,
                'completed_at' => now(),
            ])->save();

            $progress->forceFill([
                'current_step' => OnboardingStep::Review->value,
                'completed_at' => now(),
            ])->save();

            // Market selection drives compliance defaults (module doc). Apply it to the team.
            if ($profile->primary_market !== null) {
                $team->forceFill(['market' => $profile->primary_market->value])->save();
            }

            $this->audit->log('onboarding.completed', $profile, ['version' => $profile->version], $actor);

            DB::afterCommit(static function () use ($team): void {
                RunBackgroundCheckJob::dispatch((int) $team->getKey());
            });

            return $profile->refresh();
        }));
    }
}
