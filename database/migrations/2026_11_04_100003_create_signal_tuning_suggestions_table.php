<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_tuning_suggestions', function (Blueprint $table): void {
            // A proposal the team **accepts or declines** (S12). Never applied on its own.
            //
            // That is the decision this table exists to enforce rather than describe. A feature
            // that silently retunes itself is a feature nobody can debug: the member who asks
            // "why did it stop finding these?" has no answer, and neither does the person
            // supporting them. So the advisor writes rows here, the settings screen shows them
            // with their evidence, and accepting one is an ordinary audited edit to the
            // definition — the same write a member could have made by hand, with the same audit
            // entry.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // What is being proposed — a `SignalTuningKind`. An enum rather than a row, and
            // ADR 0018 §1's test is why: the application branches hard on this (accepting an
            // ignore-source writes a watch's list, accepting a threshold writes a definition's),
            // so it is a closed set the code reasons about.
            $table->string('kind', 32);

            // The definition the suggestion is about. Nullable because an ignore-source proposal
            // can be about a source across every type; `nullOnDelete` for the usual reason.
            $table->foreignId('signal_definition_id')
                ->nullable()
                ->constrained('signal_definitions')
                ->nullOnDelete();

            // The change itself, as the values the Action will apply — never a sentence. The
            // wording is composed at render time, because this screen is EN/AR like every other.
            $table->json('payload');

            // What the advisor counted to reach it. Shown beside the proposal, because a
            // suggestion a member cannot check is one they can only obey or ignore.
            $table->json('evidence');

            // `SignalTuningStatus`: proposed, accepted, declined. A declined row is **kept**, and
            // that is the point of storing status rather than deleting: the advisor must not
            // propose next week what the team turned down this week, which is the difference
            // between a suggestion and a nag.
            $table->string('status', 16);

            // A stable hash of (kind, definition, the thing being proposed), so re-running the
            // advisor refreshes a live proposal instead of stacking duplicates — and so a
            // declined proposal is recognised when it comes round again.
            $table->char('fingerprint', 40);

            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'fingerprint'], 'signal_tuning_team_fingerprint_unique');
            // The settings panel's read: this team's live proposals, newest first.
            $table->index(['team_id', 'status', 'created_at'], 'signal_tuning_team_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_tuning_suggestions');
    }
};
