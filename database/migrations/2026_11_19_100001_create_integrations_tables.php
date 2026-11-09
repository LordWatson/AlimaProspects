<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM side of PL7: one connection per provider per team, what we have already pushed, and the
 * record of every push.
 *
 * Three tables rather than one, because they answer three different questions and age at three
 * different rates. `integrations` is **live state** a member edits (connected, needs reauth, the
 * field mapping). `integration_links` is a **memory** — "their id for our row" — and is what makes
 * a second push an update instead of a duplicate; it must outlive any log retention, because
 * losing it means creating every organisation again in somebody's CRM. `integration_sync_logs` is
 * **history**, written once per attempt, read by a human looking at why something did not arrive.
 *
 * The plan's `direction` column is not here: there is only a push, and a column with one value is
 * a column that lies about what the table holds the day a pull arrives (ADR 0034).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 20);

            // Encrypted at the cast, not hashed: a token has to be *used*, so the protection
            // available is `APP_KEY` plus never writing it to a log. `text` because a provider's
            // access token is routinely longer than 255 characters once encrypted.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Which account at the provider this points at — shown so a member can tell they
            // connected the right CRM, and checked on reconnect so a silent account switch does
            // not quietly repoint every link.
            $table->string('external_account_id', 100)->nullable();
            $table->string('external_account_name')->nullable();

            // Field mapping, the auto-sync rule, pipeline/owner defaults. Json because every key
            // in it is provider-specific and a column per provider is a migration per connector.
            $table->json('settings')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            // Truncated at write. A provider's error body can be a page of HTML, and this is shown
            // on a settings screen.
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            // One connection per provider per team. A second would be two answers to "where does
            // this lead go", and the first push would pick whichever row it read first.
            $table->unique(['team_id', 'provider']);
        });

        Schema::create('integration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('local_type', 20);
            $table->unsignedBigInteger('local_id');
            $table->string('external_type', 30);
            $table->string('external_id', 100);
            // What we last sent, hashed. The skip that stops a nightly re-push of 4,000 unchanged
            // organisations — see `SyncLeadToCrmAction`.
            $table->string('synced_hash', 64);
            $table->timestamp('synced_at');

            // Named by hand: the generated name for five columns is past MySQL's 64 characters.
            $table->unique(
                ['integration_id', 'local_type', 'local_id', 'external_type'],
                'integration_links_local_unique',
            );
            // "Where did this lead end up" — the lead detail screen's question.
            $table->index(['team_id', 'local_type', 'local_id'], 'integration_links_local_index');
        });

        Schema::create('integration_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('local_type', 20);
            $table->unsignedBigInteger('local_id');
            // One column for what happened, not an `action` beside an `outcome`: "created" and
            // "failed" are answers to the same question, and two columns would allow the pair
            // "created / failed" that means nothing (ADR 0034).
            $table->string('outcome', 12);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'created_at']);
            $table->index(['integration_id', 'local_type', 'local_id'], 'integration_sync_logs_local_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('integration_links');
        Schema::dropIfExists('integrations');
    }
};
