<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Data\RetentionPolicyData;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Compliance\Services\DataRetention;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saves a team's retention windows (docs/compliance.md §3.4).
 *
 * Three guards, because this is a form whose output deletes people's data:
 *
 * - **The windows are clamped** to the configured floor and ceiling. A team that types `1` has
 *   misread the field; a team that types `9999` has stopped having a retention policy.
 * - **Contacted must not be shorter than uncontacted.** Refused rather than silently swapped: the
 *   two numbers mean different things — one deletes, one anonymises — and guessing which the team
 *   meant would be guessing about deletion.
 * - **Audited, at both ends.** Shortening a window and switching the sweep off are both decisions
 *   somebody made, and the entry records the old values as well as the new ones. "Who shortened
 *   retention to thirty days, and when" is the question this exists to answer (§3.8).
 */
final class SaveRetentionPolicyAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly DataRetention $retention,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ComplianceException when the windows are the wrong way round
     */
    public function handle(Team $team, User $member, RetentionPolicyData $data): RetentionPolicy
    {
        $min = $this->retention->minDays();
        $max = $this->retention->maxDays();

        $uncontacted = max($min, min($max, $data->uncontactedLeadDays));
        $contacted = max($min, min($max, $data->contactedLeadDays));

        if ($contacted < $uncontacted) {
            throw ComplianceException::retentionWindowsInverted();
        }

        return $this->teamContext->run($team, function () use ($team, $member, $data, $uncontacted, $contacted): RetentionPolicy {
            $before = $this->retention->windowsFor($team);

            $policy = DB::transaction(function () use ($team, $member, $data, $uncontacted, $contacted): RetentionPolicy {
                $policy = RetentionPolicy::query()->firstOrNew(['team_id' => $team->getKey()]);

                $policy->fill([
                    'team_id' => $team->getKey(),
                    'updated_by' => $member->getKey(),
                    'is_enabled' => $data->isEnabled,
                    'uncontacted_lead_days' => $uncontacted,
                    'contacted_lead_days' => $contacted,
                ])->save();

                return $policy;
            });

            $this->audit->log('compliance.retention_policy_saved', $policy, [
                'enabled' => $data->isEnabled,
                'was_enabled' => $before['enabled'],
                'uncontacted_days' => $uncontacted,
                'was_uncontacted_days' => $before['uncontacted_days'],
                'contacted_days' => $contacted,
                'was_contacted_days' => $before['contacted_days'],
            ], $member);

            Log::channel('compliance')->info('Retention policy saved.', [
                'team_id' => (int) $team->getKey(),
                'enabled' => $data->isEnabled,
                'uncontacted_days' => $uncontacted,
                'contacted_days' => $contacted,
            ]);

            return $policy;
        });
    }
}
