<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's `subscriptions` + `subscription_items` (Phase 16), keyed on `team_id` because the
 * billable model is {@see Team}. Cashier derives the foreign key from the
 * billable model's `getForeignKey()`, so the column name is what makes this work.
 *
 * Mirrors Cashier 16's published schema, with its two later `subscription_items` columns folded in
 * rather than bolted on afterwards: there is no deployed version of this table to alter.
 *
 * No `foreignId(...)->constrained()` here, and no `BelongsToTeam`: these are **Cashier's** models,
 * not ours, so the global tenant scope never reaches them. Tenancy is enforced where it is read —
 * every lookup goes through `$team->subscription()`, and the billing screens authorise with
 * `BillingPolicy` (ADR 0006: a scope is a convenience, a policy is the guarantee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
