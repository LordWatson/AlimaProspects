<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger for the things that have no ledger of their own (PL4).
 *
 * Billing already meters three things, and each reads an authoritative table somebody else owns:
 * `ai_requests` for AI spend, `discovery_runs` for leads, `signal_runs` for signal searches. That
 * is `UsageLedger`'s whole design — **nothing increments a counter at the point of use**, because
 * an increment the app forgot to make is an allowance a team silently overspends, and one made
 * twice is an allowance they are wrongly refused.
 *
 * A contact reveal has no such table. It is a discrete chargeable act with nothing behind it but
 * the fact that somebody clicked, so this is where that fact goes — and it is the **same** shape:
 * append-only, read by aggregate, never incremented. `usage_meters` remains the period snapshot
 * over it, exactly as it is over the other three.
 *
 * ## Why there is no `team_allowances` table
 *
 * `pre-launch.md` asks for one — a projection with `included`, `topped_up`, `used` and `reserved`.
 * There isn't one, because every one of those four is derivable from the rows here plus the plan:
 * `used` is the committed quantity, `reserved` is reserve minus commit minus release, `topped_up`
 * is the granted quantity, and `included` is the plan's figure. A second table holding the same
 * answers is a second answer, and the one that drifts is the one a customer reads.
 *
 * What a reserve does need is a **mutex**, so two clicks for the last unit cannot both succeed.
 * That is the `usage_meters` row for the same `(team, metric, period)` — a row that already exists
 * for every tuple, uniquely keyed on precisely the thing being serialised. Locking it is correct
 * rather than convenient: it is the one row whose meaning is "this team's position on this metric
 * this period".
 *
 * ## What is deliberately not here
 *
 * **No cost column.** Vendor spend stays on the tables that own it — `enrichment_attempts` per
 * call, `ai_requests` per prompt — and the margin guard sums those. A `cost_micros` here would be
 * a copy, and the copy would be the one that was wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('meter', 32);      // BillingMetric value
            $table->string('direction', 16);  // LedgerDirection value

            // Always **positive**, in the metric's own unit. The direction says which way it
            // counts, so a reader never has to know whether a negative quantity meant a release or
            // a correction — which is the ambiguity signed quantities create and that a direction
            // column exists to remove.
            $table->unsignedInteger('quantity');

            // `YYYY-MM`, the billing period this belongs to, in UTC (ADR 0012). A string because
            // every read here is an equality or a range over whole months, and a date would invite
            // a timezone to creep into the comparison.
            $table->string('period', 7);

            // What this was for: an enrichment run, a reveal, a Stripe session. A morph rather than
            // six nullable foreign keys, because the referenced thing is in six different modules
            // and the ledger must not gain a dependency on any of them.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // For a reserve: the handle a commit or a release finds it by. For a grant: nothing.
            //
            // **Not unique**, deliberately: a reservation legitimately has up to three rows — the
            // reserve, and a commit and a release beside it. What makes settling once-only is
            // therefore not an index but the `usage_meters` row lock `UsageAllowance::commit()`
            // takes before it asks whether the hold is already settled.
            $table->uuid('reservation')->nullable();

            // The external fact that caused this, where there is one: a Stripe event id on a
            // top-up. Unique, so a webhook Stripe sends twice grants the units once. This is the
            // same idempotency `usage_meters.reported` gives the overage reporter, one level down.
            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->json('meta')->nullable();

            // Append-only: no `updated_at`, because nothing updates a row. A reservation is
            // settled by **writing** a commit or a release beside it, which is what keeps the
            // history of a disputed month readable.
            $table->timestamp('created_at')->nullable();

            // The aggregate every guard runs: "what has this team used and held, on this metric,
            // this period".
            $table->index(['team_id', 'meter', 'period']);
            // The margin and usage screens, which ask across every metric for one period.
            $table->index(['team_id', 'period']);
            // Settling a reservation.
            $table->index(['reservation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ledger');
    }
};
