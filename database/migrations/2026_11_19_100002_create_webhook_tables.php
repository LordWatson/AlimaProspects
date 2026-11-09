<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks (PL7). An endpoint a team registers, and one row per attempt to reach it.
 *
 * The delivery row exists before the request is made and is updated by it, which is what makes the
 * log honest: a worker that dies mid-POST leaves a `pending` row rather than no evidence that
 * anything was tried. It also makes "redeliver" a job dispatch rather than a rebuild — the payload
 * it will send is the one that was composed at the time, not what the lead looks like today.
 *
 * `secret` is **encrypted, not hashed**, for the reason the inbound one is (`SignalWebhookSecret`):
 * signing a body means recomputing an HMAC, which needs the secret itself. It is shown in the clear
 * exactly once, by the call that mints it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // 2048 rather than 255: a Zapier or Make catch-hook URL carries a long opaque token,
            // and a truncated URL is an endpoint that 404s for reasons nobody can see.
            $table->string('url', 2048);
            $table->text('secret');
            // The last characters of the secret, so a member can tell two endpoints apart without
            // the secret ever being read back. Same rule as the inbound one.
            $table->string('hint', 8);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            // Consecutive failed deliveries. Reset by a success, which is what makes the
            // auto-disable threshold mean "this endpoint is gone" rather than "this endpoint has
            // had a bad day since March".
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            // The `data` half of the envelope only. The rest of it — the id, the event name, the
            // timestamp — is built from this row at send time, so the stored json holds no second
            // copy of what the row already says.
            $table->json('payload');
            $table->string('state', 10);
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('status_code')->nullable();
            // Truncated to 1KB at write: a response body is somebody else's output and this is a
            // column a screen renders.
            $table->text('response_snippet')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            // The log screen, newest first, per endpoint.
            $table->index(['webhook_endpoint_id', 'id']);
            // The retention sweep.
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
