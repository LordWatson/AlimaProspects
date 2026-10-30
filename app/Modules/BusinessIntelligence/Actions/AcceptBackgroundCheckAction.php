<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Actions;

use App\Models\User;
use App\Modules\BusinessIntelligence\Data\AcceptBackgroundCheckData;
use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Exceptions\BackgroundCheckException;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Actions\CreateIcpAction;
use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\DB;

/**
 * Accept a ready background-check report. Merges its findings into the team's Business Profile —
 * filling only blank fields, never overwriting what the business itself entered — and optionally
 * seeds a suggested ICP from the findings. Marks the report accepted. Everything is one transaction.
 */
final class AcceptBackgroundCheckAction
{
    /**
     * How many suggested industries / target customers to turn into ICP criteria (keep it focused).
     */
    private const int MAX_ICP_CRITERIA_PER_TYPE = 5;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly CreateIcpAction $createIcp,
    ) {}

    public function handle(Team $team, BusinessIntelReport $report, AcceptBackgroundCheckData $data, ?User $actor = null): BusinessIntelReport
    {
        if (! $report->isReady()) {
            throw BackgroundCheckException::notReviewable();
        }

        return $this->teamContext->run($team, fn (): BusinessIntelReport => DB::transaction(function () use ($team, $report, $data, $actor): BusinessIntelReport {
            $fresh = BusinessIntelReport::query()
                ->where('team_id', $team->getKey())
                ->whereKey($report->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isReady()) {
                throw BackgroundCheckException::notReviewable();
            }

            $findings = BackgroundCheckFindings::fromAi($fresh->findings ?? []);

            if ($data->applyToProfile) {
                $this->mergeIntoProfile($team, $findings);
            }

            if ($data->seedIcp) {
                $this->seedIcp($team, $findings, $actor);
            }

            $fresh->forceFill([
                'status' => BackgroundCheckStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by' => $actor?->getKey(),
            ])->save();

            $this->audit->log('business_intel.accepted', $fresh, [
                'version' => $fresh->version,
                'applied_to_profile' => $data->applyToProfile,
                'seeded_icp' => $data->seedIcp,
            ], $actor);

            return $fresh;
        }));
    }

    /**
     * Fill blank Business Profile fields from the findings. Never clobbers a value the team set.
     */
    private function mergeIntoProfile(Team $team, BackgroundCheckFindings $findings): void
    {
        $profile = BusinessProfile::query()
            ->where('team_id', $team->getKey())
            ->lockForUpdate()
            ->first();

        if (! $profile instanceof BusinessProfile) {
            return;
        }

        $updates = [];

        if (($profile->industry === null || $profile->industry === '') && ($findings->suggestedIndustries[0] ?? null) !== null) {
            $updates['industry'] = $findings->suggestedIndustries[0];
        }

        if (($profile->value_proposition === null || $profile->value_proposition === '') && $findings->offering !== '') {
            $updates['value_proposition'] = $findings->offering;
        }

        if ($updates !== []) {
            $profile->forceFill($updates)->save();
        }
    }

    /**
     * Build a suggested ICP from the findings and persist it via the ICP module's create Action.
     */
    private function seedIcp(Team $team, BackgroundCheckFindings $findings, ?User $actor): void
    {
        $criteria = [];

        foreach (array_slice($findings->suggestedIndustries, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $industry) {
            $criteria[] = new CriterionData(type: CriterionType::Industry, value: $industry, weight: 60);
        }

        foreach (array_slice($findings->targetCustomers, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $title) {
            $criteria[] = new CriterionData(type: CriterionType::JobTitle, value: $title, weight: 40);
        }

        if ($criteria === []) {
            return;
        }

        $this->createIcp->handle($team, new IcpData(
            name: 'Suggested from background check',
            description: 'Auto-generated from your AI background check. Tune the weights to match who you really want.',
            isActive: false,
            criteria: $criteria,
        ), $actor);
    }
}
