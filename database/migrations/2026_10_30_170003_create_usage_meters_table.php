<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per team, per metered thing, per billing period (Phase 16).
 *
 * **This table is a record, not a counter.** The authoritative usage numbers already exist —
 * `ai_requests.cost_micros` for AI spend and `discovery_runs.leads_created` for leads — and the
 * guards read those directly, so enforcement never depends on an increment that a crashed worker
 * might have skipped. What those ledgers cannot do is remember what a *closed* period cost after
 * retention has pruned it, or remember how much overage has already been billed. That is what a
 * row here is for: a period's snapshot, and `reported` as the idempotency key for Stripe.
 *
 * `used` and `included` are in the metric's own unit — whole leads for `leads`, credits for
 * `ai_credits` (see `BillingMetric`), never micro-units. A column that silently held two units
 * would be a billing bug waiting to happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);

            // UTC, always — the period bounds must reach a query in UTC or a non-UTC team's
            // allowance resets on the wrong day (CLAUDE.md §2, and Reporting's DateRange rule).
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->unsignedBigInteger('used')->default(0);
            $table->unsignedBigInteger('included')->default(0);

            // Overage units already pushed to Stripe for this period. Reporting is incremental:
            // we send the difference and move this forward, so a retried command cannot bill twice.
            $table->unsignedBigInteger('reported')->default(0);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'metric', 'period_start']);
            $table->index(['metric', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_meters');
    }
};
