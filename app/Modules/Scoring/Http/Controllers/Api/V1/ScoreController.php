<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\FindActiveIcpAction;
use App\Modules\Scoring\Actions\FindScoredLeadAction;
use App\Modules\Scoring\Actions\RecordScoreFeedbackAction;
use App\Modules\Scoring\Exceptions\ScoringException;
use App\Modules\Scoring\Http\Requests\RecordScoreFeedbackRequest;
use App\Modules\Scoring\Http\Resources\LeadScoreResource;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned `/api/v1` access to a lead's ICP-fit score, consumed by the mobile app (which shows the
 * grade and explanation). Returns API Resources only (never models) and delegates to the same
 * Actions the web controller uses.
 */
final class ScoreController extends Controller
{
    public function show(Request $request, int $lead, FindScoredLeadAction $find): LeadScoreResource
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        $score = LeadScore::activeFor($model);

        if (! $score instanceof LeadScore) {
            throw ScoringException::notScored();
        }

        $this->authorize('view', $score);

        return LeadScoreResource::make($score);
    }

    /**
     * Queue a fresh score. 202: the grade arrives asynchronously, so the client re-reads `show`.
     */
    public function store(Request $request, int $lead, FindScoredLeadAction $find, FindActiveIcpAction $findActiveIcp): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);
        $this->authorize('rescore', $this->transientScore($team));

        $activeIcp = $findActiveIcp->handle($team);

        if ($activeIcp === null) {
            throw ScoringException::noActiveIcp();
        }

        ScoreLeadJob::dispatch((int) $team->getKey(), (int) $model->getKey(), (int) $activeIcp->getKey(), force: true);

        return new JsonResponse(['data' => ['status' => 'queued']], 202);
    }

    public function feedback(
        RecordScoreFeedbackRequest $request,
        int $lead,
        FindScoredLeadAction $find,
        RecordScoreFeedbackAction $record,
    ): JsonResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        $score = LeadScore::activeFor($model);

        if (! $score instanceof LeadScore) {
            throw ScoringException::notScored();
        }

        $this->authorize('giveFeedback', $score);

        $record->handle($score, $user, $request->rating(), $request->comment());

        // The score itself is returned, now carrying the caller's verdict.
        return LeadScoreResource::make($score->load(['icp:id,name,is_active', 'feedback' => fn ($query) => $query->where('user_id', $user->getKey())]))
            ->response()
            ->setStatusCode(201);
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

    private function transientScore(Team $team): LeadScore
    {
        return (new LeadScore)->forceFill(['team_id' => $team->getKey()]);
    }
}
