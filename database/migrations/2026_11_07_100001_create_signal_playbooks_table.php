<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_playbooks', function (Blueprint $table): void {
            // A rule a team owns: *when a signal looks like this, do these things* (S14).
            //
            // The table holds two JSON columns and the reason neither is a set of columns is the
            // same in both cases: what a team may condition on, and what they may ask for, are
            // lists this plan keeps adding to, and a migration per new condition would mean a
            // deploy to let somebody filter on an amount. Both are validated into typed objects on
            // the way in (`SignalPlaybookData`) and narrowed again on the way out
            // (`SignalPlaybookConditions`, `SignalPlaybookStep`), so nothing reads raw JSON at a
            // call site.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 120);

            // Paused, and *rehearsing*, are two different statements — see `PlaybookMode`. A paused
            // rule is not evaluated at all; a rehearsing one is evaluated and writes a log row
            // having done nothing. Keeping them in separate columns is what lets a team rehearse a
            // rule for a week and then switch it on without rebuilding it.
            $table->boolean('is_active')->default(true);
            $table->string('mode', 16);

            // What the signal has to look like. Deterministic, no AI: definitions, sources,
            // jurisdictions, a heat floor, an amount band, whether the event is about a company the
            // team already has.
            $table->json('conditions');

            // What to do, **in order**. The order matters and is the member's: a conversion first
            // is what supplies the lead that an assignment, a stage move and a draft all need.
            $table->json('actions');

            // When this rule matches, don't consider the rules below it. The flag is on the rule
            // rather than on the team, because it is a statement about *this* rule's place in the
            // order ("if it's an acquisition, handle it here and stop"), and a team-wide switch
            // would make every rule's behaviour depend on an unrelated setting.
            $table->boolean('stop_after_first_match')->default(false);

            // Lower runs first. Not unique — two rules at the same priority are ordered by id,
            // which is stable and is what somebody who never touched priority would expect.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->timestamps();

            // The evaluator's read: this team's live rules, in the order they run.
            $table->index(['team_id', 'is_active', 'priority', 'id'], 'signal_playbooks_team_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_playbooks');
    }
};
