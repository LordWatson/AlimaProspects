<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            // A device registered for push (FCM/APNs via Capacitor).
            //
            // **No `team_id`.** A phone belongs to a person, not to a team: the same handset
            // receives notifications for every team its owner is a member of, and the per-team
            // decision about whether to send at all is the preference table's, not the device's.
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The provider's registration token. Long and opaque — FCM tokens run past 160
            // characters — and unique, because the same token arriving again is the same device
            // re-registering, which must update the row rather than duplicate it. It is a bearer
            // credential for sending *to* the device, so it is never logged or sent back out.
            $table->string('token', 512);
            $table->char('token_hash', 64);

            $table->string('platform', 16); // PushPlatform value

            // For pruning. A token FCM has rejected as unregistered is deleted immediately; one
            // nothing has been sent to in months is swept, because a stale token is a send that
            // silently goes nowhere.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Hashed, because MySQL cannot index 512 characters of utf8mb4 under the 3072-byte
            // key limit — and a token is matched for equality only, so a hash is all the index
            // needs to do.
            $table->unique('token_hash');
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
