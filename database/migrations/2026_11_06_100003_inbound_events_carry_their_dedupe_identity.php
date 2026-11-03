<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give an inbound event the identity it will have as a signal (S13b review, finding 8).
 *
 * **Why a stored column rather than a computed one.** `WebhookSignalSource` offers a window of
 * recent events to each run, capped at `signals.sources.webhook.max_per_run`. It had no way to tell
 * an event already turned into a signal from one never classified, so the cap was spent on events
 * the run would immediately discard: a customer pushing two hundred events in a burst — exactly
 * what a backfill or a retry loop looks like — had the newest fifty offered on every run and the
 * other hundred and fifty never classified at all. Silently, with no error and nothing in the
 * breakdown to show it.
 *
 * Excluding them needs the signal's identity, which is `sha1('webhook:' . team . ':' . key)`. That
 * is derivable in PHP and not in SQL — SQLite has no `sha1()` and MySQL's is a different function
 * name — so a filter written in the database has to read a column. Hence this one, plus the index
 * the `whereNotExists` against `signals.dedupe_hash` needs to be worth writing.
 *
 * Char(40) and not a string: it is a hex sha1, always, and a fixed width is what lets MySQL compare
 * it without a length prefix. Nullable with a backfill rather than NOT NULL, because the table
 * already exists in development databases from earlier in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_inbound_events', function (Blueprint $table): void {
            $table->char('dedupe_hash', 40)->nullable()->after('idempotency_key');

            // Scoped by team first, the way every other lookup in this module is: the exclusion
            // subquery filters on both, and a hash is only ever compared within one tenant.
            $table->index(['team_id', 'dedupe_hash'], 'signal_inbound_events_team_dedupe_index');
        });

        // The same expression `SignalInboundEvent::dedupeIdentityFor()` computes. Written here as a
        // literal rather than by calling the model, for the reason the S13a migration records: a
        // migration is a historical fact and must not change meaning when the class does.
        DB::table('signal_inbound_events')
            ->select(['id', 'team_id', 'idempotency_key'])
            ->whereNull('dedupe_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('signal_inbound_events')
                        ->where('id', $row->id)
                        ->update(['dedupe_hash' => sha1('webhook:'.$row->team_id.':'.$row->idempotency_key)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('signal_inbound_events', function (Blueprint $table): void {
            $table->dropIndex('signal_inbound_events_team_dedupe_index');
            $table->dropColumn('dedupe_hash');
        });
    }
};
