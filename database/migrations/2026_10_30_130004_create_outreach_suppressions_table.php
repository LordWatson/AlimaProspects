<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_suppressions', function (Blueprint $table): void {
            // The do-not-contact list (docs/compliance.md §3.2–3.3). Two scopes: **global**, which
            // every team honours, and **team**, which is that team's own list. An opt-out is
            // written global, because a person who asked to be left alone asked everyone.
            //
            // Owned by Outreach now and read by it at draft and send time; Compliance (Phase 15)
            // takes ownership of the table and adds the DSAR and retention tooling over it. It is
            // a real table rather than a stub precisely because a stub that returns "not
            // suppressed" is an opt-out silently ignored.
            $table->id();

            // Null for a global entry; set for a team's own. The FK makes a team's list go with
            // the team, and a global entry outlive every one of them.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('scope', 8);  // SuppressionScope value

            // The scope expressed as one comparable string — `global`, or `team:17`. It exists only
            // so the uniqueness below can be a plain unique index: MySQL treats NULLs in a unique
            // index as distinct, so `['team_id', 'type', 'value_hash']` would let the same global
            // entry be written any number of times — and a duplicate in a suppression list is a
            // race waiting to double-send.
            $table->string('scope_key', 24);

            $table->string('type', 8);   // SuppressionType value

            // SHA-256 of the normalised value, and the only form matched against. A hash because
            // this list is, by construction, a register of people who want nothing to do with us:
            // keeping their address in the clear to prove we are not contacting them is the
            // opposite of data minimisation (§3.9). Deterministic — unsalted — because the whole
            // point is to recognise the same address arriving from somewhere else later.
            $table->char('value_hash', 64);
            // Enough to recognise an entry on screen ("j***@example.com", "acme.com", "***4821")
            // and no more, so a team can manage its own list without the list being a contact book.
            $table->string('value_preview', 64);

            $table->string('reason', 16);  // SuppressionReason value
            // Where it came from: "replied asking us to stop", "bounced twice", a ticket id. Short
            // and team-authored; never a copy of the message.
            $table->string('note', 180)->nullable();

            $table->timestamp('suppressed_at');
            $table->timestamps();

            // One entry per identity per scope. The lookup index as well as the guarantee.
            $table->unique(['scope_key', 'type', 'value_hash']);
            // The send-time check: every candidate hash in one indexed pass.
            $table->index(['type', 'value_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_suppressions');
    }
};
