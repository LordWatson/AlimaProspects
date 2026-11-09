<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Enrichment\Http\Resources\LeadContactResource;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Observers\LeadWebhookObserver;
use App\Modules\Integrations\Services\WebhookDispatcher;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;

/**
 * The bridge from Alima's own domain events to a customer's webhook endpoints (PL7).
 *
 * **Integrations listens outwards and no module knows it exists**, which is the same arrangement
 * the Leads Workspace timeline uses: three events it did not define, consumed by a queued listener
 * that writes nothing anybody upstream depends on. Adding a webhook event is a method here, never a
 * line in whoever raised it.
 *
 * **Queued**, and that is not an optimisation. A listener on an after-commit event runs inside the
 * caller's job (ADR 0024's lesson: an exception in one failed a watch run and re-billed the Serper
 * search), so composing a payload and queueing five deliveries must not happen inside a discovery
 * run. The dispatcher also swallows its own failures, which is the second layer of the same rule.
 *
 * **One class with four methods** rather than four listener classes. They share the payload helper
 * and the team lookup, and four files whose bodies are two lines each would be four places to
 * forget `TeamContext`.
 *
 * **The payload is built with the app's own API resources**, resolved inside the team's context.
 * That is what makes masking automatic rather than remembered: `LeadContactResource` already sends
 * one already-masked field per value (ADR 0029), so a value nobody paid to reveal cannot reach a
 * customer's Zapier by somebody forgetting to think about it here.
 */
final class RelayWebhookEvents implements ShouldQueue
{
    /**
     * A run can persist a lot of signals. Past this the fan-out is the story rather than the news,
     * and a customer's endpoint is being used as a bulk export.
     *
     * shortcut: the newest `MAX_SIGNALS_PER_RUN` of a run are relayed and the rest are not
     * mentioned; upgrade to a single `signals.detected` summary event if a customer asks for the
     * tail.
     */
    private const int MAX_SIGNALS_PER_RUN = 50;

    /**
     * Teams already looked up, by id.
     *
     * `lead.created` fires once per company and a discovery run finds hundreds, all for the same
     * team — so without this the "one query per run" claim in ADR 0034 was one query per *lead*,
     * inside the creating transaction. Scoped to the instance, which the container resolves per job.
     *
     * @var array<int, Team|null>
     */
    private array $teams = [];

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * A lead was re-graded against an ICP — its fit, and usually its confidence, have moved.
     */
    public function onLeadScored(LeadScored $event): void
    {
        $this->relayLead($event->teamId, $event->leadId, WebhookEvent::LeadUpdated);
    }

    /**
     * Contact details were found or checked for a lead.
     */
    public function onLeadEnriched(LeadEnriched $event): void
    {
        $team = $this->team($event->teamId);

        if ($team === null) {
            return;
        }

        $this->webhooks->dispatch($team, WebhookEvent::ContactEnriched, fn (): array => $this->teamContext->run(
            $team,
            function () use ($event): array {
                $lead = Lead::query()->with('contacts')->find($event->leadId);

                if ($lead === null) {
                    return ['lead_id' => $event->leadId];
                }

                return [
                    'lead_id' => (int) $lead->getKey(),
                    'company_name' => $lead->company_name,
                    'contacts' => LeadContactResource::collection($lead->contacts)->resolve($this->request()),
                ];
            },
        ));
    }

    /**
     * A watch run persisted new trigger events.
     *
     * One delivery per signal rather than one per run, because a customer's automation is about an
     * event at a company — "a run finished" is a fact about our scheduler, and nobody outside Alima
     * can act on it.
     */
    public function onSignalsDetected(SignalsDetected $event): void
    {
        $team = $this->team($event->teamId);

        if ($team === null) {
            return;
        }

        $signals = $this->teamContext->run($team, fn (): array => Signal::query()
            ->where('signal_run_id', $event->runId)
            ->orderByDesc('id')
            ->limit(self::MAX_SIGNALS_PER_RUN)
            ->get()
            ->all());

        foreach ($signals as $signal) {
            $this->webhooks->dispatch(
                $team,
                WebhookEvent::SignalDetected,
                fn (): array => $this->teamContext->run(
                    $team,
                    fn (): array => SignalResource::make($signal)->resolve($this->request()),
                ),
            );
        }
    }

    /**
     * A company was added to the team's list. Raised by {@see LeadWebhookObserver}.
     */
    public function onLeadCreated(int $teamId, int $leadId): void
    {
        $this->relayLead($teamId, $leadId, WebhookEvent::LeadCreated);
    }

    private function relayLead(int $teamId, int $leadId, WebhookEvent $event): void
    {
        $team = $this->team($teamId);

        if ($team === null) {
            return;
        }

        $this->webhooks->dispatch($team, $event, fn (): array => $this->teamContext->run(
            $team,
            function () use ($leadId): array {
                $lead = Lead::query()->with(['evidence', 'activeScore', 'contacts'])->find($leadId);

                return $lead === null
                    ? ['id' => $leadId]
                    : LeadResource::make($lead)->resolve($this->request());
            },
        ));
    }

    private function team(int $teamId): ?Team
    {
        if (array_key_exists($teamId, $this->teams)) {
            return $this->teams[$teamId];
        }

        return $this->teams[$teamId] = Team::query()->find($teamId);
    }

    /**
     * The resources want a request and there is none in a queue worker.
     *
     * A bare instance rather than the container's console request, so what a resource decides from
     * the request — a `when()` on an ability, a locale — is the same whether the event came from a
     * web request, an API token or the scheduler. A webhook body that varied by what triggered it
     * would be a contract that is not one.
     */
    private function request(): Request
    {
        return Request::create('/');
    }
}
