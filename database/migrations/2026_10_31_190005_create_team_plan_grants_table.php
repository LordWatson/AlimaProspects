<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan an operator granted, rather than one Stripe is charging for (Phase 19b).
 *
 * Two jobs, and both were gaps:
 *
 * 1. **Manual billing.** An invoiced customer has a signed contract and no card. The grant is what
 *    entitles them; `PlanResolver` reads it above the Stripe subscription.
 * 2. **Bespoke allowances.** `config/billing.php` gives Enterprise `null` seats, leads and credits,
 *    which `PlanData` reads as *unlimited* — but a signed contract says 25 seats and 40,000 leads,
 *    not "unlimited". The three nullable override columns are where those numbers live. Null means
 *    "use the plan's", not "unlimited", which is why they are separate from the plan key.
 *
 * `starts_at` / `ends_at` make a grant a **window**. The expiry is the point: a goodwill upgrade
 * while somebody's billing is sorted out should stop being an upgrade on its own, rather than
 * becoming permanent because nobody remembered. A null `ends_at` is an open-ended grant, which is
 * what a signed contract is.
 *
 * Several grants per team are allowed on purpose — the history of what a tenant was entitled to and
 * when is worth keeping, and the resolver takes the one whose window contains now (newest first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_plan_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32);

            // Null = take the plan's own allowance. **Not** null = unlimited; that is what the
            // plan's own null means, and conflating the two would silently uncap a contract.
            $table->unsignedInteger('seats')->nullable();
            $table->unsignedInteger('leads')->nullable();
            $table->unsignedBigInteger('credits')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // Why this tenant has a plan nobody paid Stripe for. Required by the request, because a
            // grant with no note is one the next operator cannot safely revoke.
            $table->string('note', 500);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // The resolver's query: this team's grants, newest window first.
            $table->index(['team_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_plan_grants');
    }
};
