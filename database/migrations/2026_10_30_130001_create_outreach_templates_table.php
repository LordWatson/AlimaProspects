<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_templates', function (Blueprint $table): void {
            // A team's reusable starting point for a message (Phase 11): the angle they want the
            // AI to take, in their own words, for one channel and tone. Not a mail-merge template
            // — drafting is a model call, and this is the brief it is given. Team-scoped via
            // BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Nulled rather than cascaded: a template the team relies on must not disappear
            // because the person who wrote it left.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 80);
            $table->string('channel', 16);  // OutreachChannel value
            $table->string('tone', 16);     // OutreachTone value
            // The locale the template is written for, so a bilingual team keeps one per language
            // rather than one that reads oddly in the other. SupportedLocale value.
            $table->string('locale', 8)->default('en');

            // Email only; null for LinkedIn and call scripts, which have no subject line.
            $table->string('subject', 180)->nullable();
            // The brief itself: what to say, what to avoid, what the ask is.
            $table->text('body');

            // The one offered first for its channel. Enforced as "at most one per channel" by
            // SaveOutreachTemplateAction rather than by a partial index, which MySQL lacks.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['team_id', 'name']);
            // The picker: this team's templates for the channel being drafted.
            $table->index(['team_id', 'channel', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_templates');
    }
};
