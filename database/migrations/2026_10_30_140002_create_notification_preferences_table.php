<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            // Which channels a member wants for one kind of notification, in one team. Per team
            // because the same person may run their own side project and work in someone else's
            // team, and want to be told very different things about each.
            //
            // **A row means "the member has an opinion".** With no row, the type's own defaults
            // apply (`NotificationType::defaultChannels()`), so adding a notification type never
            // needs a backfill and never arrives switched off for everyone.
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('type', 48); // NotificationType value

            // One boolean per channel rather than a JSON list: "is mail on for this type" is the
            // only question ever asked of this table, and a column answers it without decoding.
            $table->boolean('in_app')->default(true);
            $table->boolean('mail')->default(true);
            $table->boolean('push')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'team_id', 'type']);
        });

        Schema::create('notification_settings', function (Blueprint $table): void {
            // The member's digest and mute settings for one team — the things that are about *all*
            // their notifications rather than one type, which is why they are not columns on the
            // table above.
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('digest', 8)->default('off'); // DigestFrequency value

            // The hour the digest goes out, **in the team's timezone** (0–23). Stored as an hour
            // rather than a time because the sweep runs hourly and a digest at 08:17 is not a
            // feature anyone asked for.
            $table->unsignedTinyInteger('digest_hour')->default(8);

            // When the last digest actually went out, so an hourly sweep can't send twice — the
            // same `reminded_at` idempotency `lead_tasks` uses.
            $table->timestamp('digest_sent_at')->nullable();

            // "Not this week." A deliberate hard mute with an end date rather than a boolean,
            // because a mute nobody remembers switching on is a product that looks broken.
            $table->timestamp('muted_until')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
            // The digest sweep: everyone whose digest is on, filtered by hour in PHP against each
            // team's timezone.
            $table->index(['digest', 'digest_hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_preferences');
    }
};
