<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something the platform wants to tell its tenants (Phase 19b).
 *
 * Bilingual, unlike the admin panel itself — the panel is internal and English by decision, but an
 * announcement is **read by customers**, and half of them are in the UAE (ADR 0007). So the body is
 * stored per locale rather than as one string, and the screen falls back to English when a
 * translation is missing rather than showing nothing.
 *
 * Targetable by plan and market, because most announcements are not for everybody: a price change
 * concerns the people on that plan, and a UAE regulatory note concerns the UAE. Null targets mean
 * everybody, which is the honest default for maintenance and outages.
 *
 * `notify` is the opt-in fan-out. It is **aggregated** — one notification per team, in
 * `AnnounceTopLeadsJob`'s shape, never one row per member per team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 16)->default('info');

            // Per-locale title and body: `{"en": "...", "ar": "..."}`.
            $table->json('title');
            $table->json('body');

            // Null = everybody. A list of plan keys / market values otherwise.
            $table->json('plans')->nullable();
            $table->json('markets')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('notify')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The query every tenant page makes: what is live now.
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            // Per **person**, not per team: one colleague dismissing a banner should not hide it
            // from the rest of their team, who have not read it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
    }
};
