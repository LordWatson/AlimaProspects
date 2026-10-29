<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\FindActiveIcpAction;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Policies\LeadPolicy;
use App\Modules\Scoring\Actions\FindScoredLeadAction;
use App\Modules\Scoring\Actions\RecordScoreFeedbackAction;
use App\Modules\Scoring\Exceptions\ScoringException;
use App\Modules\Scoring\Http\Requests\RecordScoreFeedbackRequest;
use App\Modules\Scoring\Http\Resources\LeadScoreResource;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Policies\LeadScorePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lead score screen (Inertia). Thin: authorise via {@see LeadPolicy}
 * and {@see LeadScorePolicy}, delegate to the Find/Record Actions (or queue {@see ScoreLeadJob}),
 * and render the grade, breakdown and explanation.
 */
final class ScoreController extends Controller
{
    public function show(Request $request, int $lead, FindScoredLeadAction $find, FindActiveIcpAction $findActiveIcp): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        $score = LeadScore::activeFor($model);
        $activeIcp = $findActiveIcp->handle($team);

        return Inertia::render('scoring/Show', [
            'lead' => LeadResource::make($model)->resolve($request),
            'score' => $score === null ? null : LeadScoreResource::make($score)->resolve($request),
            'activeIcp' => $activeIcp === null ? null : [
                'id' => $activeIcp->id,
                'name' => $activeIcp->name,
                'criteria_count' => $activeIcp->criteria->count(),
            ],
            'can' => [
                'rescore' => $user->can('rescore', $this->transientScore($team)),
                'giveFeedback' => $user->can('giveFeedback', $this->transientScore($team)),
            ],
        ]);
    }

    /**
     * Queue a fresh score for the lead against the team's active ICP. Forced, because the member is
     * asking for it explicitly — an unchanged lead would otherwise be left as it is.
     */
    public function store(Request $request, int $lead, FindScoredLeadAction $find, FindActiveIcpAction $findActiveIcp): RedirectResponse
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Alima is grading this lead — this only takes a moment.'),
        ]);

        return back();
    }

    public function feedback(
        RecordScoreFeedbackRequest $request,
        int $lead,
        FindScoredLeadAction $find,
        RecordScoreFeedbackAction $record,
    ): RedirectResponse {
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Thanks — that helps Alima grade better.'),
        ]);

        return back();
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

    /**
     * A transient, team-scoped score for authorising abilities that don't need a stored row.
     */
    private function transientScore(Team $team): LeadScore
    {
        return (new LeadScore)->forceFill(['team_id' => $team->getKey()]);
    }
}
