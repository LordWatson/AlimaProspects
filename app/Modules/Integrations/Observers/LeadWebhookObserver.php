<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Observers;

use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Listeners\RelayWebhookEvents;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Observers\LeadProvenanceObserver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises `lead.created` (PL7).
 *
 * An observer rather than a new domain event, on purpose: Lead Sourcing has no `LeadCreated` event,
 * and adding one would mean editing a module with no interest in webhooks so that this one can hear
 * about something it can already see. `Lead::observe()` takes more than one observer and
 * {@see LeadProvenanceObserver} is already registered — the
 * same mechanism ADR 0028 chose so a guarantee survives the next creation path, which is exactly
 * the property wanted here.
 *
 * It relays **inline** and that is affordable because the relay is lazy: the endpoint lookup is one
 * memoised query per process, and the payload closure is only called when a team actually has an
 * endpoint subscribed. A discovery run for a team with no webhooks costs one query for the run.
 * A team that *has* subscribed to `lead.created` pays a resource resolution per company, which is
 * what subscribing to a per-record event means — {@see WebhookEvent::isHighVolume()}
 * is what warns them about it on the screen.
 *
 * Running inside the creating transaction is also right rather than merely convenient: the delivery
 * row is written there, so a discovery run that rolls back takes its webhooks with it, and the job
 * is only queued `afterCommit`.
 *
 * It swallows its own failures. A lead saved and a webhook not sent is a bad afternoon; a lead that
 * cannot be saved because a webhook failed is a broken product.
 */
final class LeadWebhookObserver
{
    public function __construct(private readonly RelayWebhookEvents $relay) {}

    public function created(Lead $lead): void
    {
        try {
            $this->relay->onLeadCreated((int) $lead->team_id, (int) $lead->getKey());
        } catch (Throwable $e) {
            Log::error('Could not queue the lead.created webhook.', [
                'lead_id' => $lead->getKey(),
                'team_id' => $lead->team_id,
                'exception' => $e::class,
            ]);
        }
    }
}
