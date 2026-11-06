<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A signal records where its subject is, and which compliance regime that implies
     * (S8, ADR 0019).
     *
     * This is the column that closes the defect S8 exists for. The outreach restriction on a
     * converted lead used to come from the **watch's** market, which was sound while every watch
     * was UAE and wrong in both directions the moment one could span places: a London funding round
     * under a UAE watch got a PDPL restriction it is not subject to, and a Dubai relocation under a
     * UK watch got **none** — the serious direction, since nothing published can supply the prior
     * consent PDPL requires (`docs/compliance.md` §3.3, §5a).
     *
     * Both are set at **extraction**, from the AI's free-text `location` through the deterministic
     * `SignalJurisdictionResolver`, and neither is ever recomputed. That is deliberate: the place
     * map will grow, and a lead's restriction must not change because somebody tidied a lookup
     * table. `resolved_market` is denormalised from `jurisdiction` for the same reason — it is the
     * answer as it was given, not an answer re-derived later.
     *
     * Nullable, because "the article named nowhere we recognise" is a real and common state. Null
     * does **not** mean unrestricted: conversion falls back to the watch's market and then to the
     * most restrictive of its regions, so an unresolved place fails toward the restriction.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            // ISO-3166-1 alpha-2. Two characters, but 8 wide to match `currency` beside it and to
            // leave room for a subdivision code if a market ever needs one.
            $table->string('jurisdiction', 8)->nullable()->after('location');
            // A `Market` value, or null where the code maps to no regime the business holds.
            $table->string('resolved_market', 16)->nullable()->after('jurisdiction');
        });

        // No index. Nothing queries by jurisdiction yet — the feed filters by status, type and
        // date, and conversion reads one row it already has. An index added on a guess is an index
        // nobody can justify later; S12's reporting is where this is likely to earn one.
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropColumn(['jurisdiction', 'resolved_market']);
        });
    }
};
