<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Signals\Actions\IngestSignalEventAction;
use App\Modules\Signals\Http\Requests\IngestSignalEventRequest;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/signal-events` — a customer pushes their own events in (S13b, ADR 0023 §7).
 *
 * **Thin even by this codebase's standards**, and deliberately so: every control that makes an
 * inbound event safe is in {@see IngestSignalEventRequest} (the signature, the timestamp window,
 * the bounded schema, the fields a caller may not set) or on the route (the capability, the token
 * ability, its own rate limit). What is left here is one Action call and a status code.
 *
 * **202, not 201.** The event has been accepted, not acted upon: it is classified, scored and
 * clustered by the next run of a watch that uses its signal type, exactly as a news article is. A
 * 201 would promise a resource the caller can go and read, and there is deliberately no such
 * endpoint — a pushed event is not a signal until Alima has decided it is one.
 *
 * A **replay answers 202 as well**. A machine retrying asked for the event to exist, and it does;
 * reporting a conflict would make a correct client look broken and tempt an integration into
 * treating its own retries as errors. `created` in the body is how a caller that cares can tell.
 */
final class SignalEventController extends Controller
{
    public function __construct(private readonly IngestSignalEventAction $ingest) {}

    public function store(IngestSignalEventRequest $request): JsonResponse
    {
        [$event, $created] = $this->ingest->handle(
            definition: $request->definition(),
            idempotencyKey: $request->idempotencyKey(),
            event: $request->event(),
            payload: $request->payload(),
        );

        // Deliberately not an API Resource: there is no resource here. The caller gets back their
        // own idempotency key so a log line can be correlated, and whether this call was the one
        // that stored it. Nothing about how Alima judged the event, because nothing has yet.
        return response()->json([
            'data' => [
                'idempotency_key' => $event->idempotency_key,
                'created' => $created,
                'received_at' => $event->received_at->toIso8601String(),
            ],
        ], 202);
    }
}
