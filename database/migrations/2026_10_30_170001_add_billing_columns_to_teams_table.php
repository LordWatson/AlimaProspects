<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's customer columns — on `teams`, not `users` (Phase 16).
 *
 * The billable party is the **tenant**, because that is what the plan, the seats and the
 * allowances belong to: one person may own two teams on two plans, and two owners may share one
 * subscription. Putting `stripe_id` on `users` would make the subscription follow whoever happened
 * to click subscribe, which is the one thing a multi-team product must not do (ADR 0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        // Every team that already exists gets the full trial from **today**, not from the day it
        // was created. Backdating it would end the trial of every existing tenant the moment this
        // migration ran — a deploy that silently switches customers off is not a billing rollout.
        DB::table((new Team)->getTable())
            ->whereNull('trial_ends_at')
            ->update(['trial_ends_at' => now()->addDays((int) config('billing.trial_days', 14))]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
