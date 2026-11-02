<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tenants have already been told about an announcement (Phase 19b).
 *
 * A ledger rather than a flag, because `announcements.notified_at` can only say "the fan-out
 * finished". `AnnounceToTenantsJob` walks **every** team, which is the one job in the product that
 * does; if it dies at team 3,000 of 4,000 and the queue retries it, the first 2,999 get told a
 * second time. Telling every customer twice is precisely how the next announcement becomes
 * something people filter, so the guard has to be per team rather than per announcement.
 *
 * The unique index is what makes it a guard rather than a log: the insert is the claim, so two
 * workers racing the same chunk cannot both send.
 *
 * Deliberately **not** `team_id`-scoped for tenancy purposes — it is platform bookkeeping about
 * tenants, like `usage_meters`, and nothing a tenant can read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // The guard. One claim per tenant per announcement, enforced by the database rather
            // than by the job remembering where it got to.
            $table->unique(['announcement_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_notifications');
    }
};
