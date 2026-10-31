<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a tenant is billed (Phase 19b) — `Billing\Enums\BillingMode`.
 *
 * Three modes, and the two new ones exist because `config/billing.php` already anticipated them:
 *
 * - `stripe` — self-serve checkout. Today's behaviour, and the default, so every existing team is
 *   unaffected by this migration.
 * - `manual` — invoiced outside the product. No checkout, no card, no metering; the plan comes from
 *   a row in `team_plan_grants`. This is the Enterprise case the price list already describes with
 *   a null `amount` ("price on application") and a `stripe_price` that only exists once a deal is
 *   signed.
 * - `disabled` — no billing at all. Internal tenants, demos, pilots and partners.
 *
 * Note what this column is **not**: it is not whether the team has a plan, and it does not grant
 * one. `PlanResolver` never reads it — a grant is consulted *above* the Stripe subscription
 * precisely so that turning billing off never turns entitlement off, but "never turns it off" is
 * not "turns it on". A team moved to `manual` or `disabled` keeps whatever it already had: a live
 * subscription, a trial, or the default plan. **Without a grant it is on the default plan**, and if
 * it was trialling it drops there silently when the trial lapses — which is why both the
 * provisioning summary and the billing screen say so before the mode is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('billing_mode', 16)->default('stripe')->index();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex(['billing_mode']);
            $table->dropColumn('billing_mode');
        });
    }
};
