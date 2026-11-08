<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Exceptions\EnrichmentException;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Enrich a selection of leads (PL3).
 *
 * Deliberately **not** a `Bus::batch`. A batch's value is a completion callback and a progress
 * figure over a set of jobs, and neither is worth what it costs here: each lead already has its own
 * run row carrying its own state, the screen already listens for `LeadEnrichmentUpdated` per lead,
 * and a batch would add a second place where "how far along is this" is answered — one of which
 * would be wrong whenever a job was retried. The leads list shows per-lead state because that is
 * what a member acts on.
 *
 * What it does add over a loop in a controller is the two things that must not be left to the
 * caller:
 *
 * - **A hard cap.** `enrichment.max_bulk_leads` bounds what one click can spend. Refused rather
 *   than silently truncated: a member who selected four hundred leads and got eighty enriched would
 *   have no way of knowing which.
 * - **One decision per lead, through the same Action.** Every refusal reason — no contacts,
 *   enriched last week, already in flight — is the single-lead Action's to make, so a bulk request
 *   and a single click cannot disagree about what is worth running.
 */
final class RequestBulkEnrichmentAction
{
    public function __construct(
        private readonly RequestLeadEnrichmentAction $request,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * Request `$scope` for each lead, returning the runs that were created or found in flight.
     *
     * @param  list<int>  $leadIds
     * @return list<EnrichmentRun>
     *
     * @throws EnrichmentException when more leads were selected than one request may carry
     */
    public function handle(Team $team, array $leadIds, EnrichmentScope $scope, User $actor): array
    {
        $ids = array_values(array_unique(array_filter($leadIds, static fn (int $id): bool => $id > 0)));

        $max = max(1, (int) config('enrichment.max_bulk_leads', 50));

        if (count($ids) > $max) {
            throw EnrichmentException::tooManyLeads($max);
        }

        return $this->teamContext->run($team, function () use ($team, $ids, $scope, $actor): array {
            // One query for the lot, read through the team scope — so an id that is not this
            // team's simply is not here, rather than being refused one at a time.
            $leads = Lead::query()
                ->whereIn('id', $ids)
                ->with('contacts')
                ->get();

            $runs = [];

            foreach ($leads as $lead) {
                $run = $this->request->handle($team, $lead, $scope, $actor);

                if ($run instanceof EnrichmentRun) {
                    $runs[] = $run;
                }
            }

            return $runs;
        });
    }

    /**
     * What a selection would cost at worst, in **units** of the team's allowance, for the
     * "Enrich selected (est. N credits)" label.
     *
     * An estimate and an upper bound: it counts every lead and every contact, and the run itself
     * then refuses the ones with nothing to do. A low estimate is the figure a member quotes back
     * when the bill is larger, so this errs high — and the label says "est." for the same reason.
     *
     * @param  list<int>  $leadIds
     */
    public function estimateUnits(Team $team, array $leadIds, EnrichmentScope $scope): int
    {
        return $this->teamContext->run($team, function () use ($leadIds, $scope): int {
            $leads = Lead::query()->whereIn('id', $leadIds)->withCount('contacts')->get();

            if (! $scope->needsContacts()) {
                return $leads->count() * max(1, (int) config('enrichment.max_people_per_run', 3));
            }

            return (int) $leads->sum(static fn (Lead $lead): int => max(0, (int) $lead->contacts_count));
        });
    }
}
