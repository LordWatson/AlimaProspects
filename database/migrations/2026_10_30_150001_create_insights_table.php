<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights', function (Blueprint $table): void {
            // One stored, dismissable insight for a team (Phase 14). Derived data: every row can be
            // rebuilt from the leads, the pipeline and the Reporting read-model, which is why the
            // retention sweep is free to delete them (`insights:prune`).
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);      // InsightType value
            $table->string('status', 16);    // InsightStatus value
            $table->string('severity', 16);  // InsightSeverity value

            // AI prose, and **only** AI prose. The deterministic insights (stale leads, contact
            // today) leave both null and are worded on the client from `type` + `payload`, so they
            // read in whichever language the member has the app set to (ADR 0007). The AI digest
            // cannot do that — it is written once, in the team's locale, like the notification
            // emails (see docs/modules/notifications.md, Known limitations).
            $table->string('headline')->nullable();
            $table->string('body', 1000)->nullable();

            // Ids, counts and company names — the structured half, which every type carries and
            // which the screen renders. Never a contact detail: an insight points at a lead, and
            // the lead owns the person's data (docs/compliance.md §5a).
            $table->json('payload');

            // The window the insight was computed over, in UTC. Stored so a row read tomorrow
            // still says what it was about rather than implying "now".
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // Which prompt wrote it, and what that cost — the same trace columns `lead_scores`
            // carries. Null on a deterministic insight, which spends nothing.
            $table->string('prompt_key', 64)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->timestamp('generated_at');

            // sha1-free by design: a readable key (`<type>:<local date>[:n]`) so a row says for
            // itself which day and which insight it is, and a support question can be answered by
            // reading it. 64 chars is comfortably inside MySQL's 3072-byte index limit.
            $table->string('dedupe_key', 64);

            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // Idempotence, and the reason the generator can run twice in an hour without
            // apologising: the key carries the type and the team's local date, so a second run on
            // the same day finds the row and leaves it alone — **including when it has since been
            // dismissed**, which a member would otherwise watch come straight back.
            $table->unique(['team_id', 'dedupe_key']);
            // The feed: a team's insights of a given status, newest first.
            $table->index(['team_id', 'status', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
