<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop `signal_watches.event_types`, now that the pivot carries it (ADR 0018 §5).
     *
     * Separate from the data migration before it so each is reversible on its own: rolling back
     * recreates the column and **refills it from the pivot**, which leaves the previous migration's
     * `down()` with nothing to restore and no order to get wrong.
     *
     * The plan also specified a kept `event_keys` column "for the audit trail of what was watched
     * when". It is not added: `SaveSignalWatchAction` already records the keys in the audit row on
     * every save, and a second copy on the row is a copy that can go stale.
     */
    public function up(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropColumn('event_types');
        });
    }

    public function down(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            // Nullable on the way back, because the refill below cannot run until the column
            // exists and a not-null column with no default would refuse to be added to a table
            // that already has rows.
            $table->json('event_types')->nullable()->after('location');
        });

        DB::table('signal_watches')->orderBy('id')->select('id')->chunk(200, function (iterable $watches): void {
            foreach ($watches as $watch) {
                $keys = DB::table('signal_watch_definition')
                    ->join('signal_definitions', 'signal_definitions.id', '=', 'signal_watch_definition.signal_definition_id')
                    ->where('signal_watch_definition.signal_watch_id', $watch->id)
                    ->orderBy('signal_definitions.id')
                    ->pluck('signal_definitions.key')
                    ->all();

                DB::table('signal_watches')
                    ->where('id', $watch->id)
                    ->update(['event_types' => json_encode($keys, JSON_UNESCAPED_SLASHES)]);
            }
        });
    }
};
