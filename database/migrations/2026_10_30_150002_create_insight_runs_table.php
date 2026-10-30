<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_runs', function (Blueprint $table): void {
            // One row per team per day: "this team's insights have been worked out for this date".
            //
            // It exists because the *absence* of insights is a real outcome. An earlier version
            // asked `max(insights.generated_at)` whether a team was due, which means a run that
            // legitimately produces nothing — no stale leads, nobody to call, a model with nothing
            // to say — leaves no trace, is due again an hour later, and pays for the Reporting
            // aggregates and an AI call every hour until midnight. The marker is written whatever
            // the run found, so a quiet day costs one run rather than seventeen.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // The date **in the team's timezone**, which is the unit "once a day" is measured in
            // (see InsightGenerator). Stored as a date string so the comparison needs no
            // conversion and cannot drift with DST.
            $table->date('local_date');

            $table->timestamp('generated_at');

            // What the run did, which is also the only record of a run that produced nothing.
            $table->unsignedSmallInteger('insights_created')->default(0);
            $table->boolean('ai_called')->default(false);
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->timestamps();

            // The idempotence guarantee, enforced by the database rather than by a query that
            // might not run first.
            $table->unique(['team_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_runs');
    }
};
