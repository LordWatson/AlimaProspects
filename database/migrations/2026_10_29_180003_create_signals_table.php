<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table): void {
            // A single wealth/liquidity event kept from a watch run (ADR 0011): an AI-classified,
            // AI-summarised news item about a person or company. Stores a person's name and public
            // role only — never email or phone (data minimisation; see docs/compliance.md §5a).
            // Team-scoped; unique per team on url_hash so one article never becomes two signals.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_watch_id')->constrained('signal_watches')->cascadeOnDelete();
            $table->foreignId('signal_run_id')->constrained('signal_runs')->cascadeOnDelete();

            $table->string('event_type', 32);   // SignalEventType value
            $table->string('status', 16);        // SignalStatus value

            $table->string('headline');
            $table->string('summary', 500);      // AI summary, clamped to 500 chars

            // The subject of the event — a person and/or company. All nullable: a funding round may
            // name only the company, a relocation only the person.
            $table->string('subject_person_name')->nullable();
            $table->string('subject_person_role')->nullable();
            $table->string('subject_company_name')->nullable();
            $table->string('counterparty_name')->nullable();   // e.g. the acquirer

            // Deal size, as integer minor units in its currency (CLAUDE.md money rule); both nullable
            // when the snippet gives no amount.
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 8)->nullable();         // Currency enum value

            $table->string('location')->nullable();

            // AI scores (0–100): confidence this is a real, correctly-classified event; relevance to
            // this team's ICP (how likely a high-net-worth prospect for them).
            $table->unsignedTinyInteger('confidence');
            $table->unsignedTinyInteger('relevance');

            // One-line next step — always LinkedIn / referral / introduction, never cold email (UAE
            // PDPL, ADR 0007).
            $table->text('suggested_approach')->nullable();

            $table->string('source_url', 2048);
            $table->string('source_name')->nullable();
            $table->timestamp('published_at')->nullable();     // best-effort, UTC

            // sha1 of the canonicalised URL (lower-cased host, utm_*/fragment stripped), so the same
            // article with tracking parameters dedupes. 40 hex chars.
            $table->char('url_hash', 40);

            // The clamped Serper item the signal was built from (title, snippet, link, source, date).
            $table->json('raw');

            // The Lead this signal was converted into (S4); one-way FK, nulled if the lead is deleted
            // so Lead Sourcing never depends on Signals.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // Who dismissed/converted it, and when.
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();

            $table->timestamps();

            // One article → one signal per team.
            $table->unique(['team_id', 'url_hash']);
            // The feed: a team's signals of a given status, newest first.
            $table->index(['team_id', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
