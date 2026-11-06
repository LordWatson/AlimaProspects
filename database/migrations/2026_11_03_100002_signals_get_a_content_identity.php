<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            // `sha1(normalised source name + normalised headline)`: the article's identity when
            // its link is not one (S11). Stored for every signal, not only the redirected ones,
            // because it is the input to the backfill and to any later question about what else
            // the same outlet said.
            //
            // Nullable: a headline that reduces to nothing identifying has no content hash, and
            // inventing one would merge two articles that share only their punctuation.
            $table->char('content_hash', 40)->nullable()->after('url_hash');

            // The identity this row is de-duplicated by: the content hash behind a known redirector
            // (`signals.redirect_hosts`), the canonical URL hash everywhere else.
            //
            // Added nullable so the column can be filled from `url_hash` before the unique index
            // goes on it — a non-nullable column cannot be added to a populated table without a
            // default, and a default of `''` would be a value two rows could share.
            $table->char('dedupe_hash', 40)->nullable()->after('content_hash');
        });

        // Every existing signal's identity **is** its URL hash: that is what the unique index
        // enforced until now, so copying it across is the one backfill that cannot change which
        // rows are considered duplicates. `content_hash` is deliberately left null — deriving it
        // needs `SignalItemNormaliser`, and a migration that calls application code is a migration
        // that breaks the day that code changes. `signals:cluster-backfill` fills it.
        DB::table('signals')->whereNull('dedupe_hash')->update([
            'dedupe_hash' => DB::raw('url_hash'),
        ]);

        Schema::table('signals', function (Blueprint $table): void {
            $table->char('dedupe_hash', 40)->nullable(false)->change();
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The identity moves. `insertOrIgnore` in `RunSignalWatchAction` is what makes a re-run
            // idempotent, and it is the unique index that makes `insertOrIgnore` mean anything — so
            // this index **is** the redirect fix, not the column.
            $table->unique(['team_id', 'dedupe_hash'], 'signals_team_dedupe_unique');

            // `url_hash` is kept as data: it is still the truth about the link, it is what a
            // pre-S11 row was keyed by, and "which signal came from this URL?" is a question the
            // backfill and any future source-level work both ask. Indexed, no longer unique — two
            // Google redirect tokens for one article legitimately produce one row now, and the old
            // constraint would have refused the second insert rather than recognising it.
            $table->dropUnique('signals_team_id_url_hash_unique');
            $table->index(['team_id', 'url_hash'], 'signals_team_url_index');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_url_index');
            $table->unique(['team_id', 'url_hash'], 'signals_team_id_url_hash_unique');
            $table->dropUnique('signals_team_dedupe_unique');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropColumn(['content_hash', 'dedupe_hash']);
        });
    }
};
