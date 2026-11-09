<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Actions\ConnectIntegrationAction;
use App\Modules\Integrations\Actions\DisconnectIntegrationAction;
use App\Modules\Integrations\Actions\UpdateIntegrationSettingsAction;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Http\Requests\UpdateIntegrationSettingsRequest;
use App\Modules\Integrations\Jobs\PushLeadToCrmJob;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\OAuthStateService;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Connecting, configuring and disconnecting a CRM (PL7).
 *
 * **`connect` is a POST although it only redirects**, for the reason Billing's checkout is: it
 * mints a single-use `state` with a ten-minute life, and a GET that creates something is a GET a
 * link prefetcher fires — which here would burn the state a member is about to use.
 *
 * **`callback` is a GET, because the provider decides that**, and it is the one route here with no
 * CSRF token behind it. What stands in for one is the `state`: minted for this team, this user and
 * this provider, deleted as it is read. Without it, a crafted callback URL opened by a member
 * connects *the attacker's* CRM to the member's team, and every lead that team finds from then on
 * is pushed into it. See {@see OAuthStateService}.
 */
final class CrmIntegrationController extends Controller
{
    public function connect(
        Request $request,
        string $provider,
        OAuthStateService $states,
        CrmConnectorRegistry $connectors,
    ): RedirectResponse {
        $team = $this->team($request);
        $this->guard($team);
        // **`connect`, not `viewAny`.** The latter only asks whether the caller has a team, which
        // let any member connect or replace the team's CRM (see the policy's note).
        $this->authorize('connect', Integration::class);

        $crm = $this->provider($provider);
        $connector = $connectors->find($crm);

        abort_if($connector === null, 404);

        $state = $states->issue($team, $this->user($request), $crm);

        // Away from the application entirely, so an Inertia redirect is wrong here.
        return redirect()->away($connector->authorizeUrl($state));
    }

    public function callback(
        Request $request,
        string $provider,
        OAuthStateService $states,
        ConnectIntegrationAction $connect,
    ): RedirectResponse {
        $team = $this->team($request);
        $this->guard($team);
        // The same check as `connect`, and not only because a member should not reach it: the
        // `state` proves this callback is one *we* started, and the policy proves the person
        // finishing it is allowed to.
        $this->authorize('connect', Integration::class);

        $crm = $this->provider($provider);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($code === '' || ! $states->consume($state, $team, $this->user($request), $crm)) {
            throw IntegrationException::invalidState();
        }

        $connect->handle($team, $this->user($request), $crm, $code);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':provider connected.', ['provider' => $crm->label()]),
        ]);

        return to_route('integrations.index');
    }

    /**
     * The auto-sync rule and the deal switch.
     *
     * Through a FormRequest and an Action like everything else, although it is four fields: turning
     * auto-sync on authorises unattended writes into somebody's CRM, and that is exactly the change
     * an Owner needs an audit entry for. An earlier draft validated and wrote inline and left no
     * trail; "a class for three fields" lost to the rule, correctly.
     */
    public function update(
        UpdateIntegrationSettingsRequest $request,
        Integration $integration,
        UpdateIntegrationSettingsAction $save,
    ): RedirectResponse {
        $this->guard($this->team($request));
        $this->authorize('manage', $integration);

        $save->handle(
            $integration,
            $this->user($request),
            $request->autoSync(),
            $request->minConfidence(),
            $request->minFit(),
            $request->createDeals(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync settings saved.')]);

        return back();
    }

    public function destroy(
        Request $request,
        Integration $integration,
        DisconnectIntegrationAction $disconnect,
    ): RedirectResponse {
        $this->guard($this->team($request));
        $this->authorize('manage', $integration);

        $disconnect->handle($integration, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disconnected.')]);

        return back();
    }

    /**
     * Push one lead now, because somebody asked.
     *
     * Queued rather than done in the request even for one lead: the push is several calls to
     * somebody else's API, and a member who clicked a button should not be looking at a spinner
     * while a CRM rate-limits us. The job is unique per (integration, lead), so a double click is
     * one push.
     */
    public function sync(Request $request, Integration $integration, Lead $lead): RedirectResponse
    {
        $team = $this->team($request);
        $this->guard($team);
        $this->authorize('sync', $integration);

        // The lead is read without the tenancy scope by the route binding, so the check that it is
        // this team's is made here rather than assumed.
        abort_unless((int) $lead->team_id === (int) $team->getKey(), 404);

        PushLeadToCrmJob::dispatch((int) $integration->getKey(), (int) $lead->getKey());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sending to your CRM.')]);

        return back();
    }

    private function provider(string $provider): CrmProvider
    {
        return CrmProvider::tryFrom($provider) ?? abort(404);
    }

    private function guard(Team $team): void
    {
        abort_unless(PrelaunchFeature::Webhooks->activeFor($team), 404);
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
