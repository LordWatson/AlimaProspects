<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Actions\SubmitEvidenceFeedbackAction;
use App\Modules\LeadSourcing\Http\Requests\SubmitEvidenceFeedbackRequest;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Flagging an item of evidence from the lead detail screen (PL1).
 *
 * The same Action the API controller calls — the verdict means the same thing however it arrived,
 * and a second implementation would be a second answer to whether a disputed fact still counts.
 */
final class EvidenceController extends Controller
{
    public function feedback(
        SubmitEvidenceFeedbackRequest $request,
        int $lead,
        int $evidence,
        SubmitEvidenceFeedbackAction $submit,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        /** @var Lead $model */
        $model = Lead::query()
            ->where('team_id', $team->getKey())
            ->with('team')
            ->findOrFail($lead);

        $this->authorize('view', $model);

        /** @var LeadEvidence $item */
        $item = $model->evidence()->with('team')->whereKey($evidence)->firstOrFail();

        $this->authorize('flag', $item);

        $submit->handle($team, $item, $user, $request->verdict(), $request->note());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->verdict()->disputesTheFact()
                // Said plainly, because it is the one verdict that changes what the team sees: the
                // member should know their flag did something rather than wonder whether it landed.
                ? __('Thanks — that reason no longer counts towards this lead.')
                : __('Thanks — noted.'),
        ]);

        return back();
    }
}
