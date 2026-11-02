<?php

declare(strict_types=1);

namespace Tests\Support;

use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * A Sentry transport that keeps every event instead of sending it (Phase 20, ADR 0017).
 *
 * The seam the whole Sentry layer is tested through. It is deliberately the **transport** and not
 * the client: everything worth asserting — `before_send` scrubbing and annotation,
 * `ignore_exceptions`, the trace sampler — happens inside the real client on the way to the
 * transport, so faking anything higher would test the fake.
 *
 * {@see self::install()} builds the application's **own** configured client (so the real
 * `config/sentry.php` is what is under test) with this transport in place of the HTTP one, and
 * binds it to the current hub.
 */
final class RecordingSentryTransport implements TransportInterface
{
    /**
     * @var list<Event>
     */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /**
     * Point the current hub at a client that records instead of sending, and return the recorder.
     *
     * A DSN has to be set for the client to attempt a send at all, so the caller is expected to
     * have configured one (`Config::set('sentry.dsn', ...)`) before calling this.
     */
    public static function install(): self
    {
        $transport = new self;

        $client = app(ClientBuilder::class)
            ->setTransport($transport)
            ->getClient();

        SentrySdk::getCurrentHub()->bindClient($client);

        return $transport;
    }

    /**
     * The first recorded event, or null.
     */
    public function first(): ?Event
    {
        return $this->events[0] ?? null;
    }
}
