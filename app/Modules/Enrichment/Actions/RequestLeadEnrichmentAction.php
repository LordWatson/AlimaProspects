<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Models\User;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Jobs\RunEnrichmentWaterfallJob;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A member asks for a lead to be enriched (PL3). Creates the run, or refuses with a reason.
 *
 * **It refuses rather than throws, four times over**, and each refusal is recorded on a run so the
 * screen can explain itself and the provider panel is not told a vendor failed:
 *
 * 1. The team does not have the feature (`prelaunch.enrichment` is off) — nothing is created at
 *    all, because a run recording "this team cannot see this button" is noise.
 * 2. The team's plan does not include enrichment (`Capability::Enrichment`) — a **capability**
 *    refusal, which leaves no run for the same reason: it says the feature is not theirs, not that
 *    a cost event happened. That is the distinction S16 drew for Signals, and it is the same one.
 * 3. There is already a run of this scope in flight — the member clicked twice, and the second
 *    click should land on the first run rather than buying a second one.
 * 4. There is nothing to do: the lead was enriched inside `reenrich_after_days`, or the scope needs
 *    contacts and the lead has none. **This one is recorded**, because "we didn't look" is
 *    something a member will ask about.
 *
 * The credit reservation is deliberately **not** here. It belongs with the spending, which is in
 * the job: a reservation taken in the request and carried through a queue would be held for as long
 * as the queue is deep, and released by nobody if the worker died.
 *
 * Nothing external happens in the transaction — the job is dispatched from `afterCommit`, so a
 * rolled-back request cannot leave a worker chasing a run that does not exist (CLAUDE.md §2).
 */
final class RequestLeadEnrichmentAction
{
    public function __construct(
        private readonly CapabilityGate $capabilities,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Request `$scope` for `$lead`, returning the run — the new one, the one already in flight, or
     * a refused one explaining why nothing will happen. Null when the team cannot see the feature
     * at all.
     */
    public function handle(Team $team, Lead $lead, EnrichmentScope $scope, ?User $actor = null): ?EnrichmentRun
    {
        if (! PrelaunchFeature::Enrichment->activeFor($team)) {
            return null;
        }

        if (! $this->capabilities->allows($team, Capability::Enrichment)) {
            // No run: a capability refusal says the feature is not theirs, which is a sales fact
            // rather than a cost event. A run row here would put every plan boundary on the
            // operations screen.
            return null;
        }

        $inFlight = EnrichmentRun::query()
            ->where('lead_id', $lead->getKey())
            ->where('scope', $scope)
            ->whereIn('state', [EnrichmentRunState::Queued, EnrichmentRunState::Running])
            ->first();

        if ($inFlight instanceof EnrichmentRun) {
            return $inFlight;
        }

        $refusal = $this->nothingToDo($lead, $scope);

        if ($refusal !== null) {
            return $this->refuse($team, $lead, $scope, $actor, $refusal);
        }

        $run = DB::transaction(function () use ($team, $lead, $scope, $actor): EnrichmentRun {
            $run = EnrichmentRun::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'requested_by' => $actor?->getKey(),
                'scope' => $scope,
                'state' => EnrichmentRunState::Queued,
            ]);

            DB::afterCommit(fn () => RunEnrichmentWaterfallJob::dispatch(
                (int) $team->getKey(),
                (int) $run->getKey(),
            ));

            return $run;
        });

        $this->audit->log('enrichment.requested', $run, [
            'lead_id' => (int) $lead->getKey(),
            'scope' => $scope->value,
        ], $actor);

        return $run;
    }

    /**
     * Why this request would find nothing — or null when it is worth running.
     */
    private function nothingToDo(Lead $lead, EnrichmentScope $scope): ?string
    {
        if ($scope->needsContacts() && $lead->contacts()->count() === 0) {
            // Finding an address for nobody is a waterfall over an empty list, and a charge for
            // nothing if a vendor bills per attempt.
            return 'enrichment.no_contacts';
        }

        if ($scope === EnrichmentScope::People && $lead->contacts()->count() > 0 && $this->enrichedRecently($lead)) {
            return 'enrichment.recently_enriched';
        }

        return null;
    }

    private function enrichedRecently(Lead $lead): bool
    {
        $days = max(1, (int) config('enrichment.reenrich_after_days', 30));

        return $lead->contacts()
            ->whereNotNull('enriched_at')
            ->where('enriched_at', '>=', Carbon::now()->subDays($days))
            ->exists();
    }

    /**
     * Record a refusal as a finished run carrying its code.
     *
     * By **code**, never by message: the admin panel reads a run's state, and a translated sentence
     * cannot be counted. It is `EnrichmentRunState::Refused` rather than `Failed` for the same
     * reason — a team with nothing to enrich is not a provider outage.
     */
    private function refuse(Team $team, Lead $lead, EnrichmentScope $scope, ?User $actor, string $code): EnrichmentRun
    {
        return EnrichmentRun::query()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'requested_by' => $actor?->getKey(),
            'scope' => $scope,
            'state' => EnrichmentRunState::Refused,
            'error_code' => $code,
            'finished_at' => Carbon::now(),
            'summary' => [],
        ]);
    }
}
