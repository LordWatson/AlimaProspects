<?php

declare(strict_types=1);

use App\Modules\Signals\Enums\SignalRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns on `signal_runs`, both from the S16 review, and both because a figure was being asked
 * a question it could not answer.
 *
 * **`paid_queries_count` — what the team is actually billed for.** `queries_count` is a *coverage*
 * figure: how many queries this run made, across every source, which is what the watch card and
 * `/admin/signal-runs` read. Since S13b three of the five sources are **free** — Companies House,
 * a feed and the webhook all configure `cost_micros_per_request => 0`, because the first shares
 * Lead Sourcing's existing quota and the other two make no provider call we pay for. So metering
 * the monthly allowance on `queries_count` charged a team for requests that cost us nothing, and on
 * a metered plan produced *overage* on them. The two numbers answer two questions and needed two
 * columns.
 *
 * **`error_code` — why a run failed, as a key rather than a sentence.** `error` is the message a
 * member reads, translated and free to be reworded. S16 made "the team is at its monthly ceiling" a
 * `Failed` run, which is right — it is a cost event and the watch card is where a team should see it
 * — but `Admin\Services\Platform\PlatformRollup` and `SignalRunOversight` both count `Failed` runs
 * as *provider* failures, so every refused cadence would have inflated the platform failure rate
 * and could have set off an alert about Serper being unwell. Branching on a message is not an
 * option (it is translated); the code is the stable thing, and it is what the API envelope already
 * carries.
 *
 * **The backfill is deliberate and it is an approximation.** `paid_queries_count` is seeded from
 * `queries_count`, which is exactly right for every run before S13b (every source was paid) and
 * slightly high for the handful since. Nothing is billed from history — `usage_meters` is written
 * from the live ledger month by month — so the alternative, a zero that reads as "this run searched
 * nothing", would be the worse lie. Documented here rather than left for somebody to infer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->unsignedInteger('paid_queries_count')
                ->default(0)
                ->after('queries_count');

            // 64 is comfortably more than any `SignalsException` code and short enough to index if
            // that is ever wanted; it is read by equality, never by prefix.
            $table->string('error_code', 64)
                ->nullable()
                ->after('error');
        });

        // Seeded from the coverage figure — see the class docblock for why that is the honest
        // approximation rather than a zero. Only finished runs: a queued or running row has not
        // written `queries_count` yet, and its own settle will write both.
        DB::table('signal_runs')
            ->whereIn('status', [SignalRunStatus::Completed->value, SignalRunStatus::Failed->value])
            ->update(['paid_queries_count' => DB::raw('queries_count')]);
    }

    public function down(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropColumn(['paid_queries_count', 'error_code']);
        });
    }
};
