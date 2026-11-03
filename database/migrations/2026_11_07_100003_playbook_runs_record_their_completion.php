<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_playbook_runs', function (Blueprint $table): void {
            // **The difference between "it matched nothing" and "it was interrupted"** (found in
            // the S14 review).
            //
            // The run row is claimed before any step executes, which is what wins the
            // once-per-signal unique — so a worker that dies in between leaves a row with an empty
            // `results` and no way to tell it apart from a rule that fired and did nothing. The
            // retry finds the pair claimed and does nothing, correctly, and the work is gone.
            //
            // It cannot be made not to happen: the claim has to come first or a race creates a
            // second task. So it is made **visible** instead. `completed_at` is stamped when the
            // results are written, and a row older than the job's timeout with none is an
            // interruption the log says so about — which is the whole argument for having an audit
            // trail rather than a success log.
            $table->timestamp('completed_at')->nullable()->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('signal_playbook_runs', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
