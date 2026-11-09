<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Enums\DeliveryState;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookEndpoint;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one thing that happened into one queued delivery per subscribed endpoint (PL7).
 *
 * **Everything that raises a webhook goes through here**, and the reason is that the callers are
 * listeners and an observer. A listener on an after-commit event runs inside the caller's job
 * (ADR 0024's lesson, learned when an exception in one failed a watch run and re-billed a Serper
 * search), so this class catches `Throwable`, logs ids and returns. Nothing a customer's webhook
 * configuration can do may fail a discovery run.
 *
 * **The endpoint lookup is memoised, and the binding is `scoped()` rather than `singleton()`.**
 * `lead.created` fires once per company and a discovery run finds hundreds, so the common case — a
 * team with no endpoints at all — must cost one query per run rather than one per lead. But the memo
 * must not outlive the job: bound as a singleton it would survive the whole life of a `queue:work`
 * process, and an endpoint added after that worker booted would receive nothing at all until
 * somebody restarted it. Per request and per job is the boundary that gives the saving without the
 * staleness.
 *
 * The **payload is composed here, at the moment of the event**, and stored on the delivery row.
 * That is what makes a redelivery twelve hours later send what happened rather than what the lead
 * looks like now, and it is also what keeps the masking honest: the resources that build it are
 * resolved in whatever context the event fired in, where a value nobody paid to reveal is already
 * masked (ADR 0029).
 */
final class WebhookDispatcher
{
    /**
     * @var array<string, list<WebhookEndpoint>>
     */
    private array $memo = [];

    /**
     * Queue this event to every endpoint that asked for it. Returns how many were queued.
     *
     * `$data` may be a **closure**, and for anything high-volume it should be: the payload is then
     * composed only when somebody is actually subscribed. `lead.created` fires once per company and
     * a discovery run finds hundreds, so the common case — a team with no endpoints — has to cost
     * one query per run rather than one resource resolution per lead.
     *
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $data  the envelope's `data`;
     *                                                                      already masked
     */
    public function dispatch(Team $team, WebhookEvent $event, array|Closure $data): int
    {
        try {
            return $this->fanOut($team, $event, $data);
        } catch (Throwable $e) {
            // Ids only — a payload here would be the customer's own lead data in a log line.
            Log::error('Webhook dispatch failed.', [
                'team_id' => $team->getKey(),
                'event' => $event->value,
                'exception' => $e::class,
            ]);

            return 0;
        }
    }

    /**
     * Queue one delivery to one endpoint, bypassing the subscription check.
     *
     * For "Send test event" and for a redelivery — both are a member asking for this endpoint
     * specifically, and refusing because the endpoint is not subscribed to the event they are
     * testing with would be refusing the only way to find out whether the URL works at all.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatchTo(WebhookEndpoint $endpoint, WebhookEvent $event, array $data): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'team_id' => $endpoint->team_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => $event->value,
            'payload' => $data,
            'state' => DeliveryState::Pending->value,
        ]);

        $this->queue($delivery);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $data
     */
    private function fanOut(Team $team, WebhookEvent $event, array|Closure $data): int
    {
        if (! PrelaunchFeature::Webhooks->activeFor($team)) {
            return 0;
        }

        $endpoints = $this->endpoints($team, $event);

        if ($endpoints === []) {
            return 0;
        }

        // Composed once for the whole fan-out, after we know somebody wants it. Two endpoints
        // subscribed to the same event receive byte-identical bodies, which is also what makes a
        // customer comparing two receivers' logs able to trust what they see.
        $payload = $data instanceof Closure ? $data() : $data;

        foreach ($endpoints as $endpoint) {
            $this->dispatchTo($endpoint, $event, $payload);
        }

        return count($endpoints);
    }

    /**
     * The team's deliverable endpoints subscribed to this event.
     *
     * Queried **without the tenancy scope and with an explicit `team_id`**, because the callers are
     * listeners and a model observer: there is no ambient `TeamContext` in a queued listener, which
     * makes the global scope a no-op and an implicit reliance on it the kind of bug that leaks one
     * team's events to another's endpoint. The team arrives as an argument, so it is used as one.
     *
     * The subscription filter is in PHP rather than in the query: `events` is a json column and the
     * one expression that would do it differs between MySQL and SQLite, which is the lesson
     * `SignalsMysqlDialectTest` exists for. A team has a handful of endpoints.
     *
     * @return list<WebhookEndpoint>
     */
    private function endpoints(Team $team, WebhookEvent $event): array
    {
        $key = $team->getKey().':'.$event->value;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $endpoints = [];

        $candidates = WebhookEndpoint::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->get();

        foreach ($candidates as $endpoint) {
            if ($endpoint->subscribesTo($event)) {
                $endpoints[] = $endpoint;
            }
        }

        return $this->memo[$key] = $endpoints;
    }

    /**
     * After the commit, so a worker cannot read a delivery row the caller is still writing.
     */
    private function queue(WebhookDelivery $delivery): void
    {
        $id = (int) $delivery->getKey();

        DB::afterCommit(static fn () => DeliverWebhookJob::dispatch($id));
    }
}
