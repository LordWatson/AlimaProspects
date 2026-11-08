<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests from people who are not users (PL5).
 *
 * The product already honoured a data-subject request **made to a tenant** — a member searches,
 * reviews and erases (`compliance.dsar.*`, Phase 15). This table is the other door: somebody who
 * has never heard of us, has no account, and found a link in an email footer. They write once and
 * every team holding them is swept.
 *
 * **Platform-level, so no `team_id` and no `BelongsToTeam`** — and the absence is deliberate twice
 * over. A request is not any one tenant's row to own, and a tenant must not be able to read it: the
 * person did not write to them. Only the admin panel reads this table.
 *
 * Three columns carry the weight:
 *
 * - **`email` is stored in the clear**, which looks wrong on a privacy table and is not. We cannot
 *   write back to a hash, and a request we cannot verify or answer is a request we have refused.
 *   It is the one personal datum the person handed us on purpose, for this purpose.
 * - **`verified_at`** is the gate. Nothing happens on an unverified request — no suppression, no
 *   purge, no admin queue entry — because an unverified form is a way to erase a competitor's
 *   prospects by typing their address into it.
 * - **`due_at`** is the one-month statutory clock, stamped at verification rather than at
 *   submission: the month runs from a request we can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 16);    // SubjectRequestType value
            $table->string('status', 24);  // SubjectRequestStatus value

            $table->string('email');
            // Both optional. A name and a company narrow a search an operator may have to run by
            // hand for an access request; neither is needed for an opt-out.
            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->text('details')->nullable();

            $table->string('phone', 32)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('due_at')->nullable();

            // What the purge did, as counts keyed by category — never values. A log of what we
            // erased for privacy reasons must not be a copy of it.
            $table->json('purge_summary')->nullable();
            $table->timestamp('purged_at')->nullable();

            // The operator who closed it, and their note. `nullOnDelete`: the request outlives the
            // staff member who handled it, which is the whole point of an accountability record.
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('completed_at')->nullable();

            // The IP the form was submitted from, for the abuse case. Kept for the retention window
            // the privacy notice states and swept with the request.
            $table->string('submitted_ip', 45)->nullable();

            $table->timestamps();

            // The admin queue: open requests, oldest due first.
            $table->index(['status', 'due_at']);
            // "Have they already written to us?" — the duplicate check on submission.
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
