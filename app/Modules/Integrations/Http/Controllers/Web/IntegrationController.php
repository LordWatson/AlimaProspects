<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Contracts\CrmRowAllowance;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Http\Resources\IntegrationResource;
use App\Modules\Integrations\Http\Resources\WebhookDeliveryResource;
use App\Modules\Integrations\Http\Resources\WebhookEndpointResource;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Integrations screen (PL7).
 *
 * Its own page at `/integrations` rather than a tab in the settings pane, and for the reason
 * Billing and Compliance are: the settings pane is a 36rem column built for forms, and a delivery
 * log with a status, a response and a retry time per row does not go in one.
 *
 * **The CRM cards are built from the registry**, so this build — which ships no connector — shows
 * the webhooks half and an honest "not available yet" for Pipedrive and HubSpot. A hard-coded pair
 * of cards would have meant a Connect button that 404s, which reads as a broken product rather than
 * as an unfinished one.
 */
final class IntegrationController extends Controller
{
    /** The log is a diagnostic, not an archive. Enough to see a pattern. */
    private const int RECENT_DELIVERIES = 100;

    public function index(Request $request, CrmRowAllowance $allowance): Response
    {
        $team = $this->team($request);

        abort_unless(PrelaunchFeature::Webhooks->activeFor($team), 404);

        $user = $this->user($request);
        $canManage = $user->can('viewAny', WebhookEndpoint::class);

        return Inertia::render('integrations/Index', [
            // A member who may not configure endpoints sees the CRM half and no URLs at all
            // (`WebhookEndpointPolicy` withholds the list itself — see its note).
            'canManage' => $canManage,
            'endpoints' => $canManage ? WebhookEndpointResource::collection(
                WebhookEndpoint::query()->where('team_id', $team->getKey())->oldest()->get(),
            )->resolve($request) : [],
            'deliveries' => $canManage ? WebhookDeliveryResource::collection(
                WebhookDelivery::query()
                    ->where('team_id', $team->getKey())
                    ->latest('id')
                    ->limit(self::RECENT_DELIVERIES)
                    ->get(),
            )->resolve($request) : [],
            'eventCatalogue' => array_map(static fn (WebhookEvent $event): array => [
                'value' => $event->value,
                'label' => $event->label(),
                'description' => $event->description(),
                'high_volume' => $event->isHighVolume(),
            ], WebhookEvent::cases()),
            'integrations' => IntegrationResource::collection(
                Integration::query()->where('team_id', $team->getKey())->get(),
            )->resolve($request),
            'providers' => array_map(static fn (CrmProvider $provider): array => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'available' => $provider->isAvailable(),
            ], CrmProvider::cases()),
            'crmRemaining' => $allowance->remaining($team),
            // Shown once, flashed by the call that minted it. Never read back from a row.
            'newSecret' => $request->session()->get('newWebhookSecret'),
        ]);
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
