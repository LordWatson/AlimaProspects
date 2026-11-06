<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop `signal_watches.location`, now that `locations` carries it (S8, ADR 0019).
     *
     * Separate from the migration before it so each is reversible on its own: rolling back
     * recreates the column and **refills it from the first entry of `locations`**, which leaves the
     * previous migration's `down()` with nothing to restore and no order to get wrong. The same
     * arrangement S7 used for `event_types`, for the same reason.
     *
     * A watch covering several places loses all but the first on a rollback. That is inherent — the
     * old column held one string — and it is recorded here rather than discovered: a rollback puts
     * the schema back, not the capability.
     */
    public function up(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropColumn('location');
        });
    }

    public function down(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            // Nullable on the way back, because the refill below cannot run until the column exists
            // and a not-null column with no default refuses to be added to a table with rows.
            $table->string('location')->nullable()->after('market');
        });

        DB::table('signal_watches')
            ->orderBy('id')
            ->select(['id', 'locations'])
            ->chunk(200, function (iterable $watches): void {
                foreach ($watches as $watch) {
                    /** @var list<string> $locations */
                    $locations = json_decode((string) $watch->locations, true) ?: [];
                    $first = is_string($locations[0] ?? null) ? $locations[0] : '';

                    DB::table('signal_watches')->where('id', $watch->id)->update(['location' => $first]);
                }
            });
    }
};
