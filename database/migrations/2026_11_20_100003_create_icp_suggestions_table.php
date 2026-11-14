<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changes Alima proposes to an ideal customer profile (docs/plans/ai-relevance-plan.md P4).
 *
 * A suggestion is **a proposal, never an edit**: accepting one is an ordinary audited profile
 * update, and declining one is kept — the unique `(icp_id, fingerprint)` is what stops the same
 * idea being raised a second time after a member has already said no to it (ADR 0022's rule for
 * the signal tuning advisor, applied here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icp_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('icp_id')->constrained()->cascadeOnDelete();

            // What to do (`IcpSuggestionAction`) to which criterion (`CriterionType` + value).
            $table->string('action', 16);
            $table->string('type', 32);
            $table->string('value');
            // The weight to add or move to; null for an exclusion or a removal.
            $table->unsignedTinyInteger('weight')->nullable();
            // One plain sentence on why, shown to the member. Model prose about the team's own data.
            $table->string('rationale', 500);

            $table->string('status', 16)->default('pending');
            // sha1(action|type|normalised value): the identity of the idea, whatever its wording.
            $table->char('fingerprint', 40);

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->timestamps();

            $table->unique(['icp_id', 'fingerprint']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icp_suggestions');
    }
};
