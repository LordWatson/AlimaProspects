<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational switches an operator can reach from a browser (Phase 19b).
 *
 * **The table holds only overrides.** Every key is declared in `config/admin.php` with its type,
 * its default and whether it is an operational switch; a row here says "an operator has changed
 * this one". That division is the whole design: not `.env`, because an operator has to be able to
 * turn Stripe off at 2am and a deploy is not a control surface; and not the table alone, because an
 * undeclared key with no type is how a settings table becomes a junk drawer.
 *
 * Platform-wide, so no `team_id` — these are ours, not a tenant's. `updated_by` because a switch
 * that changed itself is a switch nobody can ask about, and the audit entry carries the before and
 * after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            // JSON rather than a string column, so a boolean stays a boolean and a list stays a
            // list. The declared type in config is what a read is cast back to.
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
