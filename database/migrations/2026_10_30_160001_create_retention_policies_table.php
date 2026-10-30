<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table): void {
            // How long one team keeps prospect data (Phase 15, docs/compliance.md §3.4).
            // Team-scoped via BelongsToTeam, and **one row per team** — storage limitation is a
            // single promise, not a set of overlapping ones.
            //
            // A team with no row is not unprotected: `DataRetention` falls back to the
            // config defaults, so the sweep runs from the first day whether or not anybody has
            // visited the screen. The row exists to record a team's *departure* from the default.
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Off means the sweep skips this team entirely. Deliberately possible: a team under a
            // litigation hold must be able to stop deletion, and a team that cannot stop it will
            // instead avoid the product. Switching it off is audited and the screen says plainly
            // that nothing is being purged.
            $table->boolean('is_enabled')->default(true);

            // Days with no approach after which a lead is **deleted**.
            $table->unsignedSmallInteger('uncontacted_lead_days');

            // Days of silence after the last approach or activity, after which a contacted lead is
            // **anonymised** — personal data removed, the company record and the fact of the
            // approach kept. Longer than the uncontacted window in every sensible configuration,
            // which the Action enforces rather than the schema: the two columns are independent
            // numbers and a check constraint here would be a migration nobody can change.
            $table->unsignedSmallInteger('contacted_lead_days');

            // When the sweep last finished for this team, and what it did. Not an audit trail —
            // that is `audit_logs` — but the one thing the screen has to show: a retention promise
            // nobody can see being kept is indistinguishable from one that isn't.
            $table->timestamp('last_swept_at')->nullable();
            $table->unsignedInteger('last_deleted_count')->default(0);
            $table->unsignedInteger('last_anonymised_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
