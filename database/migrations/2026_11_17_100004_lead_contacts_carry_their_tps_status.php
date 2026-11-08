<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The screening verdict, denormalised onto the contact (PL5).
 *
 * `tps_checks` is the shared, paid-for cache and the source of truth. These two columns are the
 * **read path**: a lead screen renders every contact it holds, and a badge that needed a lookup per
 * contact would be an N+1 on the hottest page in the product — or a join on a table with no
 * `team_id`, which is worse.
 *
 * They hold a copy of a verdict, so they can be stale. That is handled where it matters rather than
 * here: `TpsScreener` re-screens anything older than `compliance.tps.recheck_days`, and the screen
 * says when the check was made rather than implying it is current. A number that was never
 * screened reads `unchecked`, which is not `clear` — the distinction is the whole value of the
 * column, and it is why the default is not the permissive one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            $table->string('tps_status', 16)->default('unchecked')->after('phone_source');
            $table->timestamp('tps_checked_at')->nullable()->after('tps_status');
        });
    }

    public function down(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            $table->dropColumn(['tps_status', 'tps_checked_at']);
        });
    }
};
