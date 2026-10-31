<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-admin identity (Phase 19a, ADR 0014).
 *
 * One column on `users`, not a second guard over a second table: an operator is a person with one
 * credential, one 2FA secret, one password-reset path and one audit trail, and forking all four to
 * keep the admin panel "separate" would have made each of them half as well tested. Separation is
 * a middleware stack and a route prefix, not a second user table.
 *
 * Nullable, and null is the overwhelming majority — every customer. Indexed because the only query
 * that reads it across rows is "who are the operators", which the panel asks on every visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('admin_role', 32)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['admin_role']);
            $table->dropColumn('admin_role');
        });
    }
};
