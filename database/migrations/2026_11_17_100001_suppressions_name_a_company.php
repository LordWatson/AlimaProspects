<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room on `outreach_suppressions.type` for `company_number` (PL5).
 *
 * The column was `string(8)`, which fitted every case the enum had and none of the one PL5 adds —
 * `company_number` is fourteen characters. MySQL would have truncated it to `company_` and the
 * entry would never have matched anything again, which on this table means a team believing a
 * client is suppressed when they are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_suppressions', function (Blueprint $table): void {
            $table->string('type', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_suppressions', function (Blueprint $table): void {
            $table->string('type', 8)->change();
        });
    }
};
