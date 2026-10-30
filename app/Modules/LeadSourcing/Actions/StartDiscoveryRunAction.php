<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Data\StartDiscoveryData;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use App\Modules\LeadSourcing\Services\DiscoveryQueryShaper;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a lead-discovery run for each selected provider. Gatekeeps first — provider availability,
 * the LIA compliance gate (stub until Phase 15) and the per-plan limit (stub until Billing) — then
 * resolves the query from the chosen/active ICP and **fans out**: one Pending {@see DiscoveryRun}
 * per source (so picking Companies House + Google Places queues both in parallel, each with its own
 * status, cost and retries), dispatching a {@see DiscoverLeadsJob} per run `afterCommit`. No external
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
        private readonly DiscoveryQueryShaper $shaper,
    ) {}

    /**
     * @return Collection<int, DiscoveryRun> one Pending run per selected source, in input order
     */
    public function handle(Team $team, StartDiscoveryData $data, ?User $actor = null): Collection
    {
        return $this->teamContext->run($team, function () use ($team, $data, $actor): Collection {
            // --- Gatekeeping (before any work) ---
            foreach ($data->sources as $source) {
                if (! $source->isAvailable() || ! $this->registry->has($source)) {
                    throw LeadSourcingException::providerUnavailable($source->value);
                }
            }

            $this->lia->assertDiscoveryAllowed($team);
            // @todo Billing (Phase 16): the fan-out creates one run per source, each with its own
            // `requested_limit`, but the allowance is checked/clamped once here. Reserve against
            // `$limit * count($data->sources)` (or split the limit across sources) once real quotas
            // land. Harmless while the quota is the unlimited MVP stub.
            $this->limits->ensureWithinAllowance($team);

            $icp = $this->resolveIcp($team, $data->icpId);
            $limit = $this->resolveLimit($team, $data->limit);

            // Build a query per source. Each provider accepts a different kind of query — a company
            // register wants a trade word, a places API wants a category, a web search wants a phrase
            // — so the shaper is asked once per source rather than once per run. This is external
            // work and sits outside the transaction below (CLAUDE.md external-call rule).
            //
            // An empty `terms` is refused rather than queued: it means neither the member nor the
            // shaper could say who we are looking for, and a run with no terms would spend a provider
            // call to return nothing.
            $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

            $queries = [];
            foreach ($data->sources as $source) {
                $query = $this->shaper->shape($source, $profile, $icp, $limit, $data->terms, $data->location);

                if (trim($query->terms) === '') {
                    throw LeadSourcingException::nothingToSearch();
                }

                $queries[] = [$source, $query];
            }

            return DB::transaction(function () use ($team, $queries, $icp, $limit, $actor): Collection {
                /** @var Collection<int, DiscoveryRun> $runs */
                $runs = new Collection;

                foreach ($queries as [$source, $query]) {
                    $run = DiscoveryRun::query()->create([
                        'team_id' => (int) $team->getKey(),
                        'icp_id' => $icp instanceof Icp ? (int) $icp->getKey() : null,
                        'source' => $source,
                        'status' => DiscoveryRunStatus::Pending,
                        'params' => $query->toParams(),
                        'requested_limit' => $limit,
                        'created_by' => $actor?->getKey(),
                    ]);

                    $this->audit->log('lead_sourcing.run_started', $run, [
                        'source' => $source->value,
                        'limit' => $limit,
                        'icp_id' => $run->icp_id,
                        'shaped' => $query->shaped,
                        'exclusions' => count($query->excludeTerms),
                    ], $actor);

                    $runs->push($run);
                }

                DB::afterCommit(static function () use ($team, $runs): void {
                    foreach ($runs as $run) {
                        DiscoverLeadsJob::dispatch((int) $team->getKey(), (int) $run->getKey());
                    }
                });

                return $runs;
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
