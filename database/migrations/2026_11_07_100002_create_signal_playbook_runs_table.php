<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_playbook_runs', function (Blueprint $table): void {
            // One row per (signal, playbook): what fired, what it did, and **what it refused to do
            // and why** (S14).
            //
            // The refusals are the reason this table exists rather than an audit entry. An
            // automation with no audit trail is an automation nobody trusts, and the questions
            // people actually ask are "why didn't it draft anything for Acme?" and "what would this
            // have done last week?" — neither of which a log of successes can answer.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // **`nullOnDelete`, with the name copied.** The log outlives the rule: deleting a
            // playbook must not erase the record of what it did to somebody's pipeline, and a row
            // whose rule is gone still has to render. Same reasoning as `signals.event_key`
            // (ADR 0018 §4).
            $table->foreignId('signal_playbook_id')
                ->nullable()
                ->constrained('signal_playbooks')
                ->nullOnDelete();
            $table->string('playbook_name', 120);

            // **`cascadeOnDelete`, deliberately**, and the opposite choice from the rule above. The
            // retention sweep hard-deletes a dismissed or unactioned signal (S6), and the record of
            // how we handled a prospect's event is data about that prospect: keeping it after the
            // event itself has been minimised away would be the sweep failing to do its job.
            $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();

            // The lead the chain ended up acting on, when there was one. Nullable because a rule
            // may match a signal that never becomes a lead, and `nullOnDelete` because a deleted
            // lead must not take the log with it.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // Whose authority the chain acted under — the rule's creator, or the team's owner when
            // that member has left (see `SignalPlaybookActor`). Recorded because every module Action
            // the chain calls writes this person into its own audit entry, and a log that did not
            // say so would leave somebody looking at "Dana assigned this lead" with no explanation.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // `PlaybookMode` as it was **at the time**, not as the rule reads now. A rule
            // rehearsed for a week and then switched on has a log in two halves, and relabelling
            // the first half would destroy the rehearsal somebody made the decision on.
            $table->string('mode', 16);

            // Every step, in order, with its `PlaybookActionStatus` and — where there is one — the
            // reason key that explains it. Keys and params, never a formed sentence: the log is an
            // EN/AR screen like every other (ADR 0007).
            $table->json('results');

            // Denormalised counts over `results`, so the log can be filtered and summarised without
            // every row's JSON being parsed. `refused` is the one somebody filters on.
            $table->unsignedSmallInteger('applied_count')->default(0);
            $table->unsignedSmallInteger('refused_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);

            $table->timestamps();

            // **The once-per-signal guarantee**, and the first of the four brakes. A playbook fires
            // once per signal, ever — across two watch runs, across a re-detection, across the
            // conversion event that its own conversion step raised. The insert is what claims the
            // signal, so a duplicate is a caught constraint violation rather than a second lead.
            //
            // Deliberately **not** scoped by mode: a signal has been considered by this rule, and
            // switching the rule to live is a statement about the signals that arrive next, not an
            // invitation to re-handle the ones it rehearsed on.
            $table->unique(['signal_id', 'signal_playbook_id'], 'signal_playbook_runs_once_unique');

            // The log screen's read: this team's activity, newest first.
            $table->index(['team_id', 'created_at'], 'signal_playbook_runs_team_recent_index');
            // "Show me the ones that refused something" — the first filter anybody uses.
            $table->index(['team_id', 'refused_count'], 'signal_playbook_runs_team_refused_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_playbook_runs');
    }
};
