<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S13b task 7 — events a customer pushes in, and the secrets that let them (ADR 0023 §7).
 *
 * **Two tables, and the shape of the first is the whole design.** A pushed event is *stored*, not
 * processed: the endpoint accepts it and the next run of a watch reads it through
 * `WebhookSignalSource` like any other source. So the event is classified by the same prompt,
 * thresholded against the same floors, clustered, scored and matched by exactly the code that
 * handles a news article — which is what ADR 0023 §7 means by "the customer supplies an
 * observation; Alima still decides what it means".
 *
 * That is also why the endpoint does not need the run opened up: accepting is cheap and
 * synchronous, and the expensive, subtle half is already written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_inbound_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // The type this event claims to be. Resolved from the **key** in the body within the
            // authenticated team, never taken as an id — a guessed integer must not be able to
            // write into another tenant's type (ADR 0023 §7).
            //
            // Nullable on delete rather than cascade: an event already received is a record of what
            // a customer sent, and deleting a signal type should not rewrite that history.
            $table->foreignId('signal_definition_id')->nullable()->constrained('signal_definitions')->nullOnDelete();

            // **The replay guard.** Unique per team, so the same event sent twice is accepted once
            // and acknowledged twice — which is what a machine retrying in a loop needs, and the
            // difference between idempotent and merely tolerant.
            //
            // Per team and not globally: two customers' systems can legitimately use the same
            // scheme, and a global unique would let one tenant's key collide with another's and
            // silently swallow their event.
            $table->string('idempotency_key', 128);

            // What the customer said happened. Bounded by the request's own schema before it gets
            // here; stored whole so an operator can see exactly what was sent when somebody asks
            // why a signal reads the way it does.
            $table->json('payload');

            // The fields the source turns into a `SourceItem`, lifted out of the payload so the
            // source does not have to re-read json to build one, and so they can be indexed.
            $table->string('title');
            $table->string('link', 2048)->nullable();
            $table->text('summary')->nullable();
            $table->string('source_name')->nullable();

            // When the customer says the event happened — **not** trusted as a jurisdiction or a
            // score, only as a date, and sanity-bounded on the way in.
            $table->timestamp('occurred_at')->nullable();

            // When we accepted it. The source reads a window of these, which is what keeps an event
            // nobody ever classified from being re-offered for ever.
            $table->timestamp('received_at');

            $table->timestamps();

            // The replay guard itself.
            $table->unique(['team_id', 'idempotency_key'], 'signal_inbound_events_team_key_unique');

            // The source's own question: this team's recent events for this type.
            $table->index(['team_id', 'signal_definition_id', 'received_at'], 'signal_inbound_events_team_type_index');

            // What `signals:prune` sweeps by.
            $table->index(['team_id', 'received_at'], 'signal_inbound_events_team_received_index');
        });

        Schema::create('signal_webhook_secrets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // One secret per signal type, so revoking a leaked credential costs a customer one
            // integration rather than all of them — and so a type can be deleted without leaving a
            // credential behind.
            $table->foreignId('signal_definition_id')->constrained('signal_definitions')->cascadeOnDelete();

            // **Encrypted, not hashed**, and the distinction is forced by the cryptography rather
            // than chosen. The signature is an HMAC *over the raw body*, so verifying one means
            // recomputing it — which needs the secret itself. A hash would be enough to check a
            // secret somebody presented, and useless for checking a signature they derived from it.
            //
            // So it is stored under Laravel's `encrypted` cast: a database dump yields ciphertext,
            // and reading it needs `APP_KEY`. That is the protection available for a shared secret
            // the verifier must be able to use, and pretending otherwise by hashing it would mean
            // either asking the caller to send the secret on every request — which throws away the
            // body integrity and replay binding the HMAC exists for — or not verifying at all.
            //
            // Shown in the clear exactly once, by the call that creates it.
            $table->text('secret');

            // A short, non-secret prefix shown in the UI so a customer can tell two secrets apart
            // without either being revealed.
            $table->string('hint', 16);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // One live secret per type. Rotation replaces the row rather than keeping a history:
            // two valid secrets at once is a grace period nobody asked for and a second thing to
            // revoke when one leaks.
            $table->unique(['team_id', 'signal_definition_id'], 'signal_webhook_secrets_team_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_webhook_secrets');
        Schema::dropIfExists('signal_inbound_events');
    }
};
