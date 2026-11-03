<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalInboundEvent;

/**
 * Events a customer pushed in, read back as an ordinary source (S13b, ADR 0023 §7).
 *
 * **This is the thinnest source in the product, and that is the design.** ADR 0023 §7 says a webhook
 * event "is therefore a `SourceItem` with `classification: null`", and once that is literally true
 * there is nothing else to build: the next run of a watch reads these rows, and from there the event
 * is classified by the same `signals.extract_event` prompt, thresholded against the same floors,
 * clustered, scored and matched by exactly the code that handles a news article.
 *
 * So the endpoint accepts and stores; the run does the thinking. The alternative — classifying and
 * persisting at the moment of acceptance — would have meant opening up a 1,600-line Action to share
 * its clustering, scoring and matching, and would have made a customer's retry loop able to trigger
 * an AI call each time. Batching per run is both safer and cheaper.
 *
 * **It makes no outbound request**, which is why it extends nothing: there is no credential, no
 * timeout, no circuit breaker and no cost. Everything that needed hardening happened at the door
 * (`IngestSignalEventRequest`), where the data came from outside.
 *
 * The consequence worth documenting: a pushed event appears **on the watch's cadence**, not
 * instantly, and only if a type that runs on this source is attached to a watch. An event pushed to
 * a type nobody watches is stored, swept by `signals:prune`, and never classified — which is the
 * honest outcome, because classification costs money and nobody asked for it.
 */
final class WebhookSignalSource implements SignalSourceProvider
{
    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::Webhook;
    }

    /**
     * Any definition can receive pushed events; whether any arrive is the customer's business.
     *
     * Not conditional on a secret existing: a type with no secret simply never receives anything,
     * because the endpoint refuses an unsigned request. Declining here would make the source
     * disappear from a type whose integration is being set up, which is the worst moment for it to
     * look unavailable.
     */
    public function supports(SignalDefinition $definition): bool
    {
        return true;
    }

    /**
     * Free. Receiving an event costs nothing; what it costs is the AI that classifies it, which is
     * metered where every other AI call is.
     */
    public function estimatedCostMicros(int $queries): int
    {
        return 0;
    }

    /**
     * This team's recent events for the type this query is about.
     *
     * **Read-only, like every provider** (`SignalSourceProvider`): nothing here marks an event
     * processed, because the product already knows. A classified event becomes a `Signal` whose
     * `(team_id, dedupe_hash)` is unique, so re-reading it creates nothing; one the classifier
     * refused is remembered by `SignalRejectionLedger`. The **window** is what stops an event
     * nobody ever classified from being re-offered for ever, and it is deliberately shorter than
     * that 30-day memory — otherwise a refused event would reappear the day the memory expired.
     *
     * Scoped by `team_id` explicitly beside the global scope, for the reason every query in this
     * module is: a run has no request behind it.
     *
     * @return list<SourceItem>
     */
    public function fetch(SignalSourceQuery $query): array
    {
        $definitionId = $query->config['definition_id'] ?? null;
        $teamId = $query->config['team_id'] ?? null;

        if (! is_int($definitionId) || ! is_int($teamId)) {
            return [];
        }

        $events = SignalInboundEvent::query()
            ->where('team_id', $teamId)
            ->where('signal_definition_id', $definitionId)
            ->recent((int) config('signals.sources.webhook.window_days', 7))
            ->limit(max(1, (int) config('signals.sources.webhook.max_per_run', 50)))
            ->get();

        $items = [];
        $seen = [];

        foreach ($events as $event) {
            $item = $this->toItem($event);

            if (isset($seen[$item->dedupeKey()])) {
                continue;
            }

            $seen[$item->dedupeKey()] = true;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * One stored event as a source item.
     *
     * **The identity is the idempotency key**, not the link: a customer's events may share a URL or
     * carry none at all, and the key is the thing they promised would be unique. It is already
     * unique per team in the database, so hashing it gives a dedupe identity that cannot collide
     * with a news article's and cannot change between runs.
     *
     * `classification` is **null**, which is the whole point: a pushed event is a customer's
     * assertion about the world, so it is read by the same prompt as a news snippet. Only an
     * authoritative, already-parsed source may assert its own event (ADR 0023 §6), and the one
     * place that rule matters most is here — a caller who could classify their own events could
     * name their own confidence.
     */
    private function toItem(SignalInboundEvent $event): SourceItem
    {
        $identity = sha1('webhook:'.$event->team_id.':'.$event->idempotency_key);

        // A link is optional — plenty of internal events have no public page — so the item carries
        // the empty string rather than a fabricated URL, and `url_hash` falls back to the identity.
        $link = $event->link ?? '';

        return new SourceItem(
            title: $event->title,
            snippet: $event->summary ?? '',
            link: $link,
            urlHash: $identity,
            source: $event->source_name,
            publishedAt: $event->occurred_at ?? $event->received_at,
            raw: [
                'title' => $event->title,
                'summary' => $event->summary,
                'link' => $link,
                'source' => $event->source_name,
                'idempotency_key' => $event->idempotency_key,
            ],
            contentHash: $identity,
            dedupeHash: $identity,
            kind: $this->kind(),
            classification: null,
        );
    }
}
