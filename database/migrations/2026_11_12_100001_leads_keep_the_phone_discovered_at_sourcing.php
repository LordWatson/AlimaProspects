<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the company switchboard number a provider gives at discovery (Google Places now requests it in
 * its field mask). It is company-level contact data — a published business line, not a named
 * person's — so it is stored on the lead, unlike the personal contacts Enrichment derives; Enrichment
 * uses it as the phone on a lead's first contact when the site states none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
