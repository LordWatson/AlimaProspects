<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Actions\SubmitEvidenceFeedbackAction;
use App\Modules\LeadSourcing\Http\Requests\SubmitEvidenceFeedbackRequest;
use App\Modules\LeadSourcing\Http\Resources\LeadEvidenceResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Versioned `/api/v1` access to a lead's evidence (PL1), consumed by the mobile app — which shows
 * the same why-now panel as the web detail screen and lets a member flag a fact from the field,
 * which is where they are most likely to find out it is wrong.
 *
 * Thin: resolve inside the team, authorise, one Action, an API Resource. The lead is resolved
 * through a team-scoped query so a foreign id is a 404 rather than a cross-tenant read, and the
 * policy double-checks ownership on top (ADR 0006).
 */
final class EvidenceController extends Controller
{
    /**
     * @return AnonymousResourceCollection<int, LeadEvidenceResource>
     */
    public function index(Request $request, int $lead): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        return LeadEvidenceResource::collection($model->evidence);
    }

    public function feedback(
        SubmitEvidenceFeedbackRequest $request,
        int $lead,
        int $evidence,
        SubmitEvidenceFeedbackAction $submit,
    ): LeadEvidenceResource {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        /** @var LeadEvidence $item */
        $item = $model->evidence()
            ->with('team')
            ->whereKey($evidence)
            ->firstOrFail();

        $this->authorize('flag', $item);

        $submit->handle($team, $item, $user, $request->verdict(), $request->note());

        // The item as it now stands — disputed or not, with the caller's own verdict on it — so a
        // client re-renders the row it just acted on without a second request.
        return LeadEvidenceResource::make($item->fresh([
            'feedback' => fn ($query) => $query->where('user_id', $user->getKey()),
        ]));
    }

    /**
     * The lead, scoped to the team, with its evidence and the caller's own verdicts loaded.
     */
    private function lead(Team $team, int $leadId, int $forUserId): Lead
    {
        return Lead::query()
            ->where('team_id', $team->getKey())
            ->with([
                'team',
                'evidence',
                'evidence.feedback' => fn ($query) => $query->where('user_id', $forUserId),
            ])
            ->findOrFail($leadId);
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
