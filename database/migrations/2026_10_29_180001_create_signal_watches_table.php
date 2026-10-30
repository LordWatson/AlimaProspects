<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_watches', function (Blueprint $table): void {
            // What a team watches for (ADR 0011): a named set of event types in a market/location,
            // optionally shaped by an ICP, run on a cadence. Team-scoped via BelongsToTeam. Each run
            // of it is a signal_run; the events it keeps are signals.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The ICP that shapes queries (default location, buying-signal keywords); nullable so a
            // watch survives the ICP being deleted.
            $table->foreignId('icp_id')->nullable()->constrained('icps')->nullOnDelete();

            $table->string('name');
            $table->string('market', 16)->default('uae');   // Market enum value (v1: UAE only)
            $table->string('location');                      // e.g. "Dubai" — overrides the ICP geography

            // list<SignalEventType> — which event templates this watch runs.
            $table->json('event_types');

            $table->string('cadence', 16);                   // SignalWatchCadence value (daily|weekly)
            $table->boolean('is_active')->default(true);

            // The last time a run finished for this watch; the scheduler compares it to the cadence.
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // The scheduler lists active watches per team to find those due for a run.
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_watches');
    }
};
