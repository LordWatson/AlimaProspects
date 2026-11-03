<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Services\WebhookSignalSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accept one pushed event (S13b, ADR 0023 §7).
 *
 * **It stores, and that is all it does.** Nothing here classifies, scores, clusters or matches: the
 * next run of a watch reads the row through {@see WebhookSignalSource} and the event goes through
 * exactly the pipeline a news article does. Two reasons that is the right split rather than a
 * shortcut:
 *
 * - **Cost.** A customer's integration retries in a loop. Classifying at the moment of acceptance
 *   would make a retry storm an AI bill; batching per run does not.
 * - **Trust.** "The customer supplies an observation; Alima still decides what it means" is only
 *   true if the deciding happens in the code that decides for every other source. A separate
 *   ingestion path would be a second place for thresholds, clustering and scoring to drift — and the
 *   place where a caller's assertions would be most tempting to believe.
 *
 * **Idempotent by the database, not by a check.** A `(team_id, idempotency_key)` unique index is
 * what makes a replay safe, and the duplicate is caught rather than pre-queried: two concurrent
 * retries of the same event both pass a `SELECT` and only one survives an `INSERT`. Reporting
 * success for the duplicate is the contract a retrying machine needs — it asked for the event to
 * exist, and it does.
 */
final class IngestSignalEventAction
{
    /**
     * Store an event, or recognise that it is already stored.
     *
     * @param  array<string, mixed>  $event  The columns, from the request.
     * @param  array<string, mixed>  $payload  What was accepted, stored whole for an operator.
     * @return array{0: SignalInboundEvent, 1: bool} the row, and whether this call created it
     */
    public function handle(
        SignalDefinition $definition,
        string $idempotencyKey,
        array $event,
        array $payload,
    ): array {
        $teamId = (int) $definition->team_id;

        try {
            $row = DB::transaction(fn (): SignalInboundEvent => SignalInboundEvent::query()->create([
                ...$event,
                'team_id' => $teamId,
                // **From the resolved definition**, never from the body. The request resolved it
                // from a key within the authenticated team, so a guessed integer could not have
                // reached here (ADR 0023 §7).
                'signal_definition_id' => $definition->getKey(),
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'received_at' => now(),
            ]));
        } catch (QueryException $e) {
            $existing = $this->existing($teamId, $idempotencyKey);

            // Not a unique-constraint violation on our key — something else is wrong and the caller
            // should hear about it rather than be told their event was accepted.
            if ($existing === null) {
                throw $e;
            }

            Log::channel('signals')->info('Inbound signal event was a replay; acknowledged without storing.', [
                'team_id' => $teamId,
                'signal_definition_id' => (int) $definition->getKey(),
            ]);

            return [$existing, false];
        }

        Log::channel('signals')->info('Accepted an inbound signal event.', [
            'team_id' => $teamId,
            'signal_definition_id' => (int) $definition->getKey(),
            'inbound_event_id' => (int) $row->getKey(),
        ]);

        return [$row, true];
    }

    /**
     * The row a replay collided with, if that is what happened.
     *
     * Scoped by `team_id` explicitly: the key is unique *per team*, so without it a lookup could
     * return another tenant's row and this Action would hand it back as though it were the caller's.
     */
    private function existing(int $teamId, string $idempotencyKey): ?SignalInboundEvent
    {
        return SignalInboundEvent::query()
            ->where('team_id', $teamId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
