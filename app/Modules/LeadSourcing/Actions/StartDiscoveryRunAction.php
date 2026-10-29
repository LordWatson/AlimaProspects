<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\StartDiscoveryData;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Starts a lead-discovery run. Gatekeeps first — provider availability, the LIA compliance gate
 * (stub until Phase 15) and the per-plan limit (stub until Billing) — then resolves the query from
 * the chosen/active ICP, opens a Pending {@see DiscoveryRun} so the UI shows "queued" immediately,
 * and dispatches {@see DiscoverLeadsJob} `afterCommit` to do the external provider work. No external
 * call happens inside the transaction (CLAUDE.md rule).
 */
final class StartDiscoveryRunAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadSourceProviderRegistry $registry,
        private readonly DiscoveryLimitGuard $limits,
        private readonly LeadInterestGate $lia,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, StartDiscoveryData $data, ?User $actor = null): DiscoveryRun
    {
        return $this->teamContext->run($team, function () use ($team, $data, $actor): DiscoveryRun {
            // --- Gatekeeping (before any work) ---
            if (! $data->source->isAvailable() || ! $this->registry->has($data->source)) {
                throw LeadSourcingException::providerUnavailable($data->source->value);
            }

            $this->lia->assertDiscoveryAllowed($team);
            $this->limits->ensureWithinAllowance($team);

            $icp = $this->resolveIcp($team, $data->icpId);
            $limit = $this->resolveLimit($team, $data->limit);

            $query = DiscoveryQuery::fromIcp($data->source, $icp, $limit, $data->terms, $data->location);

            if (trim($query->terms) === '') {
                throw LeadSourcingException::nothingToSearch();
            }

            return DB::transaction(function () use ($team, $data, $query, $icp, $limit, $actor): DiscoveryRun {
                $run = DiscoveryRun::query()->create([
                    'team_id' => (int) $team->getKey(),
                    'icp_id' => $icp instanceof Icp ? (int) $icp->getKey() : null,
                    'source' => $data->source,
                    'status' => DiscoveryRunStatus::Pending,
                    'params' => $query->toParams(),
                    'requested_limit' => $limit,
                    'created_by' => $actor?->getKey(),
                ]);

                $this->audit->log('lead_sourcing.run_started', $run, [
                    'source' => $data->source->value,
                    'limit' => $limit,
                    'icp_id' => $run->icp_id,
                ], $actor);

                DB::afterCommit(static function () use ($team, $run): void {
                    DiscoverLeadsJob::dispatch((int) $team->getKey(), (int) $run->getKey());
                });

                return $run;
            });
        });
    }

    /**
     * The ICP to shape the run: the one asked for (team-scoped), else the team's active ICP, else
     * none. Criteria are eager-loaded so the query builder can read the signals.
     */
    private function resolveIcp(Team $team, ?int $icpId): ?Icp
    {
        $query = Icp::query()->where('team_id', $team->getKey())->with('criteria');

        if ($icpId !== null) {
            return $query->whereKey($icpId)->first();
        }

        return $query->where('is_active', true)->first();
    }

    /**
     * The run size: requested (or default), capped at the configured max and at what the team's
     * allowance leaves (unlimited in the MVP stub).
     */
    private function resolveLimit(Team $team, ?int $requested): int
    {
        $default = (int) config('lead-sourcing.defaults.limit', 25);
        $max = (int) config('lead-sourcing.defaults.max_limit', 100);

        $limit = max(1, min($requested ?? $default, $max));

        return $this->limits->quotaFor($team)->clamp($limit);
    }
}
