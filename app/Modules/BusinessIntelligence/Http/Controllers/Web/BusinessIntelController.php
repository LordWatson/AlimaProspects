<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\BusinessIntelligence\Actions\AcceptBackgroundCheckAction;
use App\Modules\BusinessIntelligence\Actions\FindReportAction;
use App\Modules\BusinessIntelligence\Actions\GetLatestReportAction;
use App\Modules\BusinessIntelligence\Actions\RerunBackgroundCheckAction;
use App\Modules\BusinessIntelligence\Data\AcceptBackgroundCheckData;
use App\Modules\BusinessIntelligence\Http\Requests\AcceptBackgroundCheckRequest;
use App\Modules\BusinessIntelligence\Http\Resources\BusinessIntelReportResource;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Actions\GetOnboardingStateAction;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The background-check review screen (Inertia). Thin: authorise via the report policy, delegate to
 * the Get/Rerun/Accept Actions, and render the latest report alongside the team's own profile so
 * the user can diff the AI's findings before accepting them. Live progress arrives over Reverb.
 */
final class BusinessIntelController extends Controller
{
    public function show(Request $request, GetLatestReportAction $getLatest, GetOnboardingStateAction $getState): Response
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientReport($team));

        $report = $getLatest->handle($team);
        $profile = $getState->handle($team)->profile;

        return Inertia::render('business-intel/Review', [
            // Reuse the API Resource so the web + API payloads can't drift.
            'report' => $report instanceof BusinessIntelReport
                ? BusinessIntelReportResource::make($report)->resolve($request)
                : null,
            'profile' => $this->serializeProfile($profile),
            'can' => ['manage' => $request->user()?->can('manage', $this->transientReport($team)) ?? false],
        ]);
    }

    public function rerun(Request $request, RerunBackgroundCheckAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('manage', $this->transientReport($team));

        $action->handle($team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alima is running a fresh background check.')]);

        return to_route('business-intel.show');
    }

    public function accept(AcceptBackgroundCheckRequest $request, int $report, FindReportAction $find, AcceptBackgroundCheckAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $report);

        $this->authorize('manage', $model);

        $action->handle($team, $model, AcceptBackgroundCheckData::fromValidated($request->validated()), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Background check accepted and applied to your profile.')]);

        return to_route('business-intel.show');
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * A transient, team-scoped report for authorising show/rerun (no stored row needed).
     */
    private function transientReport(Team $team): BusinessIntelReport
    {
        return (new BusinessIntelReport)->forceFill(['team_id' => $team->getKey()]);
    }

    /**
     * The team's own profile fields worth diffing against the AI findings.
     *
     * @return array<string, mixed>
     */
    private function serializeProfile(?BusinessProfile $profile): array
    {
        if (! $profile instanceof BusinessProfile) {
            return [
                'company_name' => null,
                'website' => null,
                'industry' => null,
                'value_proposition' => null,
                'products_services' => [],
                'customer_examples' => [],
            ];
        }

        return [
            'company_name' => $profile->company_name,
            'website' => $profile->website,
            'industry' => $profile->industry,
            'value_proposition' => $profile->value_proposition,
            'products_services' => $profile->products_services ?? [],
            'customer_examples' => $profile->customer_examples ?? [],
        ];
    }
}
