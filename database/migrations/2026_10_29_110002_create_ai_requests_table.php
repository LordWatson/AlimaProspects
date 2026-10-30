<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            // Per-call usage + cost ledger (the "ai_usage" the docs refer to is derived by summing
            // this table per team per period). Team-scoped via BelongsToTeam; the active team is
            // resolved from TeamContext at call time. Never store prompt inputs/outputs or personal
            // data here — only IDs, token counts and cost (CLAUDE.md logging rules).
            $table->id();

            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('prompt_key');
            $table->unsignedInteger('prompt_version');
            $table->string('tier');
            $table->string('model');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Subset of input tokens served from the provider's prompt cache, when reported.
            $table->unsignedInteger('cache_read_input_tokens')->nullable();

            // Cost in integer micro-units of `currency` (1_000_000 micros = one major unit, e.g. $1).
            // Micro-units preserve sub-cent per-call cost that plain minor units (cents) would round
            // away. Aggregated to plan credits by the credit guard (see docs/modules/ai-platform.md).
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->string('currency', 3);

            $table->unsignedInteger('latency_ms')->default(0);
            // True when served from our response cache (no provider call was made → zero cost).
            $table->boolean('cache_hit')->default(false);

            $table->string('status');           // AiRequestStatus value: success | failed
            $table->string('error_code')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['prompt_key', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
