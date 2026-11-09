<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Jobs;

use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Integrations\Enums\DeliveryState;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookEndpoint;
use App\Modules\Integrations\Services\WebhookFailureCounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Posts one delivery to one endpoint, signed, and records what came back (PL7).
 *
 * **The retry schedule is the queue's**, not a column the scheduler polls: `$backoff` is the plan's
 * 1m / 5m / 30m / 2h / 12h, and `$tries` is one more than that list so the final wait is honoured.
 * `next_retry_at` is written for the log screen to show, and is read by nobody — a second mechanism
 * that could disagree with the first is worse than no column at all.
 *
 * **The SSRF guard runs here, at send time, and not only on save.** The URL was checked when the
 * member typed it; DNS can change afterwards, and a host that answered publicly for the check and
 * `169.254.169.254` for the request is the whole of DNS rebinding. So the addresses are approved
 * and then **pinned** for the request (`SafeUrl::approvedIps()` / `resolveOption()`, shared with
 * the feed source and never copied), redirects are refused because a `Location` is an address
 * nobody checked, and a host that cannot be resolved is refused outright rather than attempted —
 * an endpoint we cannot pin is one we will not post a customer's lead data to.
 *
 * **A refusal by the guard is permanent and must not be retried**, so it fails the delivery
 * immediately and counts toward the auto-disable: an endpoint that now resolves to a private
 * address will never work again. A host that merely **fails to resolve** is a different thing and
 * is allowed to retry, because DNS has bad minutes — treating the two alike burned a delivery and a
 * strike on every blip. `IntegrationException::endpointRefused()` is the permanent one; anything
 * else thrown out of the post reaches the queue's backoff.
 *
 * `ShouldBeUnique` is deliberately **absent**. One delivery row is one attempt to reach one
 * endpoint with one event, and the row is created before the job; two jobs for the same row cannot
 * exist unless the queue duplicates one, and the idempotency that would matter — not posting twice
 * — cannot be guaranteed over somebody else's HTTP endpoint anyway. That is why the signed envelope
 * carries an `id`: the receiver de-duplicates, which is the only place it can be done correctly.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    /** 1m, 5m, 30m, 2h, 12h — the plan's schedule, and `$tries` is one more than its length. */
    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 1_800, 7_200, 43_200];

    /** Comfortably past the request timeout, so the worker is never the thing that gives up. */
    public int $timeout = 60;

    public function __construct(private readonly int $deliveryId) {}

    public function handle(WebhookFailureCounter $failures): void
    {
        $delivery = WebhookDelivery::query()
            ->withoutGlobalScopes()
            ->with('endpoint')
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->state === DeliveryState::Delivered) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->isDeliverable()) {
            // Switched off or disabled between queueing and running. Not a failure of the
            // endpoint — nobody should be told their URL is broken because they turned it off — so
            // the row is marked failed without touching the consecutive-failure count.
            $delivery->forceFill([
                'state' => DeliveryState::Failed,
                'next_retry_at' => null,
            ])->save();

            return;
        }

        $delivery->forceFill(['attempt' => $delivery->attempt + 1])->save();

        try {
            $response = $this->post($endpoint, $delivery);
        } catch (IntegrationException $e) {
            // The guard refused the address. Permanent: it will not improve on a retry, and the
            // message is built from one of `SafeUrl`'s own fixed strings, which name the rule
            // without echoing the customer's URL. A host that simply did not resolve is **not**
            // caught here — it is thrown as an ordinary exception so the queue tries again.
            Log::warning('Webhook refused by the SSRF guard.', [
                'delivery_id' => $delivery->getKey(),
                'endpoint_id' => $endpoint->getKey(),
                'team_id' => $delivery->team_id,
                'reason' => $e->getMessage(),
            ]);

            $this->giveUp($delivery, $endpoint, $failures, null, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'state' => DeliveryState::Delivered,
                'status_code' => $response->status(),
                'response_snippet' => $this->snippetOf($response),
                'delivered_at' => now(),
                'next_retry_at' => null,
            ])->save();

            $failures->succeeded($endpoint);

            return;
        }

        $delivery->forceFill([
            'status_code' => $response->status(),
            'response_snippet' => $this->snippetOf($response),
            // Shown, never read. See the class note.
            'next_retry_at' => $this->nextRetryAt($delivery->attempt),
        ])->save();

        // A non-2xx is a thrown exception on purpose: it is how the queue's own backoff is
        // reached. The `failed()` hook below settles the row once the attempts are spent.
        throw new RuntimeException('Webhook endpoint answered '.$response->status().'.');
    }

    /**
     * Every attempt is spent. The row says so, and the endpoint's failure count moves.
     */
    public function failed(?Throwable $e): void
    {
        $delivery = WebhookDelivery::query()
            ->withoutGlobalScopes()
            ->with('endpoint')
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->state !== DeliveryState::Pending) {
            return;
        }

        $endpoint = $delivery->endpoint;

        $this->giveUp(
            $delivery,
            $endpoint,
            app(WebhookFailureCounter::class),
            $delivery->status_code,
            // The class, not the message: an HTTP client exception's message carries the URL, and
            // a customer's catch-hook URL is a credential.
            $e === null ? null : $e::class,
        );
    }

    private function post(WebhookEndpoint $endpoint, WebhookDelivery $delivery): Response
    {
        $url = SafeUrl::normalise($endpoint->url);

        if ($url === null) {
            throw IntegrationException::endpointRefused('the scheme or host is not allowed');
        }

        // Approved **and pinned**: the guard resolves the host, and curl is then told to use those
        // addresses rather than resolving it again. See the class note on rebinding.
        try {
            $ips = SafeUrl::approvedIps($url);
        } catch (RuntimeException $e) {
            throw IntegrationException::endpointRefused($e->getMessage());
        }

        if ($ips === []) {
            // **Transient, so it throws rather than refusing.** An endpoint we cannot pin is one
            // we will not post to — but "does not resolve" is also what a DNS blip looks like from
            // here, and giving up on it would spend a delivery and a strike on somebody else's
            // nameserver having a bad minute.
            throw new RuntimeException('Webhook host did not resolve; will retry.');
        }

        $body = $delivery->body();
        $timestamp = (string) now()->getTimestamp();

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Alima-Event' => $delivery->event->value,
            // The envelope's id as a header too, so a receiver can de-duplicate without parsing
            // the body — which is what a queue in front of a handler needs to do.
            'X-Alima-Delivery' => (string) $delivery->getKey(),
            'X-Alima-Signature' => $endpoint->signatureHeader($timestamp, $body),
        ])
            ->withOptions(array_filter([
                'curl' => ($resolve = SafeUrl::resolveOption($url, $ips)) === null
                    ? null
                    : [CURLOPT_RESOLVE => [$resolve]],
            ]))
            ->withUserAgent((string) config('integrations.webhooks.user_agent', 'AlimaProspects/1.0 (+webhooks)'))
            ->timeout((int) config('integrations.webhooks.timeout', 10))
            ->connectTimeout((int) config('integrations.webhooks.connect_timeout', 5))
            // No redirects: the guard approved one address, and following a `Location` hands the
            // client one nobody checked.
            ->withoutRedirecting()
            // Streamed, so the body is **not** buffered whole into memory before we read it. A
            // receiver is somebody else's server and nothing stops it answering with a gigabyte;
            // `snippetOf()` then reads a bounded prefix. `HttpWebContentFetcher` and the feed
            // source set this for the same reason.
            ->withOptions(['stream' => true])
            ->withBody($body, 'application/json')
            ->post($url);
    }

    /**
     * A bounded read of the response body, for the log.
     *
     * Reads a little more than it will keep so the truncation is the snippet's own, and falls back
     * to `body()` only where there is no PSR stream to read — which is `Http::fake()`, whose bodies
     * are ours.
     */
    private function snippetOf(Response $response): ?string
    {
        try {
            $stream = $response->toPsrResponse()->getBody();

            return WebhookDelivery::snippet($stream->read(WebhookDelivery::SNIPPET_BYTES * 2));
        } catch (Throwable) {
            return WebhookDelivery::snippet($response->body());
        }
    }

    private function giveUp(
        WebhookDelivery $delivery,
        ?WebhookEndpoint $endpoint,
        WebhookFailureCounter $failures,
        ?int $status,
        ?string $reason,
    ): void {
        $delivery->forceFill([
            'state' => DeliveryState::Failed,
            'status_code' => $status,
            'response_snippet' => $delivery->response_snippet ?? $reason,
            'next_retry_at' => null,
        ])->save();

        if ($endpoint !== null) {
            $failures->failed($endpoint);
        }
    }

    /**
     * When the queue will try again, for the screen to show.
     *
     * `$attempt` has already been incremented, so attempt 1 looks up `backoff[0]`. Past the end of
     * the list there is no next attempt, and the row says null rather than a date that will not
     * happen.
     */
    private function nextRetryAt(int $attempt): ?CarbonImmutable
    {
        $delay = $this->backoff[$attempt - 1] ?? null;

        return $delay === null ? null : now()->addSeconds($delay);
    }
}
