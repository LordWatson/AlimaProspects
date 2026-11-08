<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Actions\RequestLeadEnrichmentAction;
use App\Modules\Enrichment\Actions\RevealContactValueAction;
use App\Modules\Enrichment\Http\Requests\RequestEnrichmentRequest;
use App\Modules\Enrichment\Http\Resources\LeadContactResource;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned `/api/v1` enrichment, consumed by the mobile app (PL3) — which is where a reveal is
 * most useful, because a member standing outside a building wants the number now.
 *
 * The reveal returns the **contact**, now unmasked, rather than the bare value: a client that
 * re-rendered a row from a string would have to merge it by hand, and the resource is the one place
 * that decides what may be shown.
 */
final class EnrichmentController extends Controller
{
    /**
     * Ask for a lead to be enriched. **202**: the waterfall is asynchronous, and the client learns
     * the result from the `teams.{id}` broadcast or by re-reading the lead.
     */
    public function store(RequestEnrichmentRequest $request, int $lead, RequestLeadEnrichmentAction $enrich): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->lead($team, $lead);

        $this->authorize('work', $model);

        $run = $enrich->handle($team, $model, $request->scope(), $user);

        if (! $run instanceof EnrichmentRun) {
            // The team cannot see the feature. Not an error — there is simply nothing to report.
            return new JsonResponse(['data' => ['status' => 'unavailable']], 200);
        }

        return new JsonResponse([
            'data' => [
                'run_id' => (int) $run->getKey(),
                'scope' => $run->scope->value,
                'state' => $run->state->value,
                // Present only on a refusal, so a client can say *why* nothing will happen.
                'reason' => $run->error_code,
            ],
        ], $run->state->isInFlight() ? 202 : 200);
    }

    public function revealEmail(Request $request, int $lead, int $contact, RevealContactValueAction $reveal): LeadContactResource
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->contact($team, $lead, $contact);

        $this->authorize('reveal', $model);

        $reveal->email($team, $model, $user);

        return LeadContactResource::make($model->fresh());
    }

    public function revealPhone(Request $request, int $lead, int $contact, RevealContactValueAction $reveal): LeadContactResource
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->contact($team, $lead, $contact);

        $this->authorize('reveal', $model);

        $reveal->phone($team, $model, $user);

        return LeadContactResource::make($model->fresh());
    }

    private function lead(Team $team, int $leadId): Lead
    {
        return Lead::query()
            ->where('team_id', $team->getKey())
            ->with(['team', 'contacts'])
            ->findOrFail($leadId);
    }

    private function contact(Team $team, int $leadId, int $contactId): LeadContact
    {
        return $this->lead($team, $leadId)
            ->contacts()
            ->with('team')
            ->whereKey($contactId)
            ->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
