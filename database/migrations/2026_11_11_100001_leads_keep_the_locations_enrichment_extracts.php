<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead quality Q1: the places a company says it operates in.
 *
 * `lead.enrich_extract` has returned a `locations` list since Phase 8 and `applyFacts()` dropped it.
 * It is deliberately **not** squeezed into `locality`/`region`/`country`: those three describe one
 * postal address a provider gave us, and an extracted location is free text a company wrote about
 * itself ("London", "Dubai & Abu Dhabi", "remote, UK"). Parsing one into the other needs a guess,
 * and a guess in an address column is worse than the honest null that is there now — so the list is
 * stored as itself, and the scorer's Geography criterion reads it alongside the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->json('locations')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('locations');
        });
    }
};
