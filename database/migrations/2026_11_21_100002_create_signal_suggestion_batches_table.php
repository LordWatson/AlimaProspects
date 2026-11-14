<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals v3 P2: one row per time a team asks Alima to suggest signal types for its business.
 *
 * It is a ledger as much as a result. Asking costs a reasoning-tier call and up to five previews,
 * and a spend a member can trigger with a button needs a row to be capped against (ADR 0020).
 * The row holds configuration only — draft signal types and counts of what a sample search
 * found — and never an article, a company name or a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_suggestion_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Nullable because a member can leave and the suggestions were still made.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->json('suggestions')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedSmallInteger('prompt_version')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_suggestion_batches');
    }
};
