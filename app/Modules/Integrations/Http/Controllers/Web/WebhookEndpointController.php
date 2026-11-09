<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Actions\DeleteWebhookEndpointAction;
use App\Modules\Integrations\Actions\RotateWebhookSecretAction;
use App\Modules\Integrations\Actions\SaveWebhookEndpointAction;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Http\Requests\SaveWebhookEndpointRequest;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookEndpoint;
use App\Modules\Integrations\Policies\WebhookEndpointPolicy;
use App\Modules\Integrations\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Managing a team's outbound endpoints (PL7).
 *
 * Every method authorises against {@see WebhookEndpointPolicy},
 * which is Owner/Admin for reading as much as for writing — see its note on why the *list* of
 * endpoints is itself worth withholding.
 *
 * **Test and redeliver both bypass the subscription check** ({@see WebhookDispatcher::dispatchTo()}).
 * Testing with an event the endpoint is not subscribed to is the only way to find out whether the
 * URL works at all before committing to an event, and a redelivery is a member asking for this
 * exact delivery again.
 */
final class WebhookEndpointController extends Controller
{
    public function store(SaveWebhookEndpointRequest $request, SaveWebhookEndpointAction $save): RedirectResponse
    {
        $team = $this->team($request);
        $this->guard($team);
        $this->authorize('create', WebhookEndpoint::class);

        $result = $save->handle(
            $team,
            $this->user($request),
            $request->url(),
            $request->events(),
            $request->isActive(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint added.')]);

        // Flashed, not in the props of a redirect target that could be refreshed: the secret is
        // shown once and then gone.
        return to_route('integrations.index')->with('newWebhookSecret', $result['secret']);
    }

    public function update(
        SaveWebhookEndpointRequest $request,
        WebhookEndpoint $endpoint,
        SaveWebhookEndpointAction $save,
    ): RedirectResponse {
        $team = $this->team($request);
        $this->guard($team);
        $this->authorize('update', $endpoint);

        $save->handle(
            $team,
            $this->user($request),
            $request->url(),
            $request->events(),
            $request->isActive(),
            $endpoint,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint updated.')]);

        return back();
    }

    public function destroy(
        Request $request,
        WebhookEndpoint $endpoint,
        DeleteWebhookEndpointAction $delete,
    ): RedirectResponse {
        $this->guard($this->team($request));
        $this->authorize('delete', $endpoint);

        // Through an Action for the audit entry: this is where a team's events stopped going
        // somewhere, and "when did we stop receiving these" has no other answer once the row and
        // its deliveries are gone.
        $delete->handle($endpoint, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint removed.')]);

        return back();
    }

    public function rotate(
        Request $request,
        WebhookEndpoint $endpoint,
        RotateWebhookSecretAction $rotate,
    ): RedirectResponse {
        $this->guard($this->team($request));
        $this->authorize('update', $endpoint);

        $secret = $rotate->handle($endpoint, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Secret rotated. Update your receiver before the next event.'),
        ]);

        return to_route('integrations.index')->with('newWebhookSecret', $secret);
    }

    /**
     * Send a test event to this endpoint.
     *
     * The payload is marked `"test": true` and carries no lead data at all. A test that posted a
     * real company would be a test that cannot be run without picking somebody's record, and a
     * receiver under construction would then have a production lead in its logs.
     */
    public function test(Request $request, WebhookEndpoint $endpoint, WebhookDispatcher $webhooks): RedirectResponse
    {
        $this->guard($this->team($request));
        $this->authorize('update', $endpoint);

        $webhooks->dispatchTo($endpoint, WebhookEvent::LeadCreated, [
            'test' => true,
            'message' => 'This is a test event from Alima.',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test event queued.')]);

        return back();
    }

    /**
     * Send a past delivery again, as it was.
     *
     * A **new row** rather than a reset of the old one: the log must keep saying that the first
     * attempt failed, or a member cannot tell a flaky endpoint from one they fixed.
     */
    public function redeliver(
        Request $request,
        WebhookDelivery $delivery,
        WebhookDispatcher $webhooks,
    ): RedirectResponse {
        $team = $this->team($request);
        $this->guard($team);

        abort_unless((int) $delivery->team_id === (int) $team->getKey(), 404);

        $endpoint = $delivery->endpoint;

        abort_if($endpoint === null, 404);

        $this->authorize('update', $endpoint);

        $webhooks->dispatchTo($endpoint, $delivery->event, $delivery->payload);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Queued again.')]);

        return back();
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
