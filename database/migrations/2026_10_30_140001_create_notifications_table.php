<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            // Laravel's database notification table, with one addition: `team_id`.
            //
            // A notification is **about work in one team** even though it belongs to one user, and
            // a member of three teams must not see the other two's notifications while they are
            // working in this one. Without the column the bell would mix them (ADR 0006).
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // Structured values, never a formed sentence: the app is EN/AR (ADR 0007), so the
            // wording is composed at render time from the type plus these — exactly the rule
            // `lead_activities.properties` follows.
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Nullable because Laravel's own notifications (a password reset, say) have no team.
            // Cascades: a deleted team takes its notifications with it.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();

            // The bell: this member's notifications in this team, newest first.
            //
            // Named explicitly, because the name Laravel would generate from four columns is 65
            // characters and MySQL's identifier limit is 64 — which SQLite does not enforce, so
            // the test suite is no guide here. Any composite index on this table needs a hand
            // name for the same reason.
            $table->index(
                ['notifiable_type', 'notifiable_id', 'team_id', 'read_at'],
                'notifications_bell_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
