<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_watchlist_entries', function (Blueprint $table): void {
            // One company or person on a watchlist (S10).
            $table->id();

            // Redundant beside the watchlist's own `team_id`, and carried for the reason the S7
            // pivot carries it: the FK above is not team-qualified, so this is the column
            // `BelongsToTeam` scopes by and the defence in depth against an entry ending up under
            // another tenant's list.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_watchlist_id')->constrained('signal_watchlists')->cascadeOnDelete();

            // `company` | `person` (`WatchlistEntityType`).
            $table->string('entity_type', 16);

            // What the member typed, kept as they typed it: this is what goes into a search query
            // and what the screen shows back to them.
            $table->string('name', 160);

            // The comparable form, from `LeadSourcing\Support\CompanyNameKey` — the **same** rule
            // `SourcedLead::dedupeKey()` uses, which is what stops this becoming a third
            // name-matching normaliser. Stored rather than computed so the unique index below can
            // exist at all, and so a lookup is an index seek rather than a scan.
            $table->string('normalised_name', 160);

            // Other ways the same company is written: "Acme Engineering" for "Acme Engineering
            // Ltd", a trading name, an Arabic rendering. Searched as well as the name, which is
            // what makes a watchlist survive a journalist's house style.
            $table->json('aliases')->nullable();

            $table->string('domain', 255)->nullable();

            // The lead this entry *is*, when the team's own pipeline put it here. Null for a name
            // somebody typed that is not yet a lead — which is a legitimate and useful state: watch
            // the company you are trying to win before you have a record for it.
            //
            // `nullOnDelete`, so deleting a lead leaves the watch intact rather than silently
            // narrowing it. The same one-way arrangement `signals.lead_id` has.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One entry per name per list. The unique index is on the **normalised** name, so
            // "ACME LTD" cannot be added beside "Acme Ltd" — which is precisely what a member
            // pasting a column out of a spreadsheet will try to do.
            $table->unique(
                ['signal_watchlist_id', 'normalised_name'],
                'signal_watchlist_entries_list_name_unique',
            );

            // The two reads: everything on a list (a run, the screen), and "is this lead already
            // watched" (the pipeline sync's idempotency).
            $table->index(['team_id', 'lead_id'], 'signal_watchlist_entries_team_lead_index');
            $table->index(['signal_watchlist_id', 'is_active'], 'signal_watchlist_entries_list_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_watchlist_entries');
    }
};
