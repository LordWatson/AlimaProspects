<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tenant's exception to what their plan includes (Phase 19b).
 *
 * **A row rather than an edit to a plan**, and that is the design rather than an implementation
 * detail: letting a pilot customer have Signals on a Starter plan by editing the Starter plan would
 * give it to every Starter customer, and doing it with a hand-written `if` would give it to them
 * with no reason and no end date. Here the reason and the expiry travel with the exception.
 *
 * `enabled` is a boolean because an override works in both directions: on, for a pilot or a
 * goodwill gesture; off, for a tenant who must not have a feature their plan includes — a trial
 * account that should not be able to send, say, or a customer whose own compliance team asked us to
 * switch something off.
 *
 * It sits **above the plan and below the kill switch** in `CapabilityGate`'s order, so an operator
 * dealing with a broken feature does not have to hunt down every override to turn it off globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_capability_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 48);
            $table->boolean('enabled');

            // Required by the request. An override with no reason is one nobody can safely remove.
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One standing override per capability per team; changing it replaces it.
            $table->unique(['team_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_capability_overrides');
    }
};
