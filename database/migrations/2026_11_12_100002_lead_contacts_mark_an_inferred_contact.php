<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark a contact whose email was **inferred** from a company domain and a named person rather than
 * found on a page. An inferred address is a guess — the MVP has no SMTP probe (ADR 0008), so it
 * cannot be told apart from any other pattern on the same domain — so it is flagged here, stored with
 * a capped confidence, and deliberately counts toward nothing in a lead's readiness. The flag is what
 * lets `LeadReadiness` and the UI tell a guess from a contact we actually read off the company's site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            $table->boolean('is_inferred')->default(false)->after('is_personal');
        });
    }

    public function down(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            $table->dropColumn('is_inferred');
        });
    }
};
