<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\SaveRetentionPolicyAction;
use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Http\Controllers\Web\Concerns\ResolvesCompliance;
use App\Modules\Compliance\Http\Requests\SaveRetentionPolicyRequest;
use App\Modules\Compliance\Http\Resources\LiaRecordResource;
use App\Modules\Compliance\Http\Resources\RetentionPolicyResource;
use App\Modules\Compliance\Services\DataRetention;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Core\Enums\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The compliance centre (Inertia). Thin: authorise via the policies and gates, validate in a
 * FormRequest, call one Action, render or redirect.
 *
 * One screen rather than four, because the four things on it are one question — "is this team
 * allowed to be doing what it is doing, and for how long" — and a member who has to visit four
 * pages to find out will visit none. The assessment itself gets its own screen, since writing one
 * is a sitting-down job.
 *
 * The retention **counts** are computed on render. They cost two aggregate queries and they are the
 * difference between a form that states a policy and one that tells a team what changing it will
 * cost tonight.
 */
final class ComplianceController extends Controller
{
    use ResolvesCompliance;

    public function index(
        Request $request,
        LegitimateInterestGate $gate,
        DataRetention $retention,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('view', $this->retentionPolicy($team));

        $teamMarket = $gate->marketFor($team);
        $policy = $this->retentionPolicy($team);
        $counts = $retention->counts($team);

        return Inertia::render('compliance/Index', [
            // One card per market Alima supports, so a team working both regimes sees both — and a
            // team that has assessed only one sees which is missing rather than nothing at all.
            'assessments' => array_map(
                fn (Market $market): array => LiaRecordResource::make($this->liaRecord($team, $market))->resolve($request),
                Market::cases(),
            ),
            'team_market' => $teamMarket->value,
            'retention' => RetentionPolicyResource::make($policy)->resolve($request),
            'retention_bounds' => [
                'min_days' => $retention->minDays(),
                'max_days' => $retention->maxDays(),
            ],
            // What tonight's sweep would do, under the windows currently saved.
            'retention_preview' => [
                'deletable' => $counts[RetentionAction::Deleted->value],
                'anonymisable' => $counts[RetentionAction::Anonymised->value],
            ],
            'privacy_notice_url' => route('privacy-notice'),
            'can' => [
                'manage_retention' => $user->can('update', $policy),
                'manage_assessment' => $user->can('update', $this->liaRecord($team, $teamMarket)),
                'export' => $user->can('compliance.export', $team),
                'dsar' => $user->can('compliance.dsar', $team),
            ],
        ]);
    }

    public function retention(SaveRetentionPolicyRequest $request, SaveRetentionPolicyAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('update', $this->retentionPolicy($team));

        $action->handle($team, $this->user($request), $request->policyData());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Retention policy saved. The nightly sweep will use it from tonight.'),
        ]);

        return back();
    }
}
