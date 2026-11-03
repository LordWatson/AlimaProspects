<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S13b — a watchlist entry learns its Companies House number (ADR 0023 §6).
 *
 * The registry is addressed by **number**, and a watchlist entry is a name a member typed or a lead
 * the pipeline mirrored. So the number has to be resolved once and remembered, because resolving it
 * is an HTTP call and the alternative is making it on every run of every watch for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_watchlist_entries', function (Blueprint $table): void {
            // The registry's identity for this account, or null where we have not established one.
            //
            // Eight characters today, but stored wider: Companies House numbers are zero-padded
            // eight-character strings for a company and longer for some other entity types (an
            // overseas entity is `OE` plus six), and a number is an **identifier rather than an
            // integer** — `00502851` is Greggs and `502851` is not a number the API accepts.
            $table->string('company_number', 32)->nullable()->after('domain');

            // When we last **tried**, which is not the same as when we last succeeded.
            //
            // This is the negative cache, and it is the column that earns its keep: most manual
            // entries will never resolve — a trading name is not a registered name, a person is not
            // a company, an overseas business is not on the UK register — and without a record of
            // the attempt every one of those would cost a search on every run, for ever. Null means
            // never asked; a timestamp with a null `company_number` means asked and not found.
            $table->timestamp('company_number_checked_at')->nullable()->after('company_number');
        });

        Schema::table('signal_watchlist_entries', function (Blueprint $table): void {
            // For the resolver's own question: which of this list's active entries still need a
            // number? Scoped by team first like every other index on this table.
            $table->index(
                ['team_id', 'company_number_checked_at'],
                'signal_watchlist_entries_team_checked_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('signal_watchlist_entries', function (Blueprint $table): void {
            $table->dropIndex('signal_watchlist_entries_team_checked_index');
            $table->dropColumn(['company_number', 'company_number_checked_at']);
        });
    }
};
