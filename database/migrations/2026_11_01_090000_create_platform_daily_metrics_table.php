<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own nightly rollup (Phase 19c).
 *
 * **A record over the ledgers, not a counter** — exactly as `usage_meters` is (ADR 0012). Every
 * row is recomputable from `ai_requests`, `discovery_runs`, `teams`, `subscriptions` and the rest,
 * and `platform:roll-up --days=` recomputes a window of them. Nothing in the application
 * increments a value here; a crashed worker therefore cannot leave the business's numbers wrong,
 * it can only leave them unwritten, which the backfill fixes.
 *
 * Why it exists at all: a cross-tenant aggregate over `ai_requests` is the one query in this
 * product with **no `team_id` to narrow it**. Every tenant-facing index is scoped by a column that
 * is also the leading edge of its index; the platform's figures are scoped by nothing, so they are
 * answered from a table with one row per day per metric per dimension instead.
 *
 * ## Shape
 *
 * `(date, metric, dimension) → value`, and the three together are unique. The generic shape is
 * deliberate: adding "AI spend by model" is a new `PlatformMetric` case and a few lines in the
 * rollup, not a migration — and a table per figure would have made the thirty-odd figures this
 * phase asks for thirty tables nobody would keep in step.
 *
 * `dimension` is **not nullable, and defaults to the empty string**. MySQL treats NULLs in a unique
 * index as distinct, so a nullable column would let the same undimensioned row be written any
 * number of times — and a duplicate here is a figure silently doubled. The same trap
 * `outreach_suppressions.scope_key` exists to avoid.
 *
 * `value` is **signed**. Most figures are counts and cannot go negative, but MRR movement can:
 * contraction and churn are negative by definition, and storing them as positive magnitudes would
 * mean every reader had to remember which sign each metric implied.
 *
 * The unit of `value` is the metric's own — see `PlatformMetric::kind()`. Counts are whole things,
 * money is **minor units** (CLAUDE.md §2) and provider cost is **cost micro-units**, because that
 * is what the AI and discovery ledgers store and converting on the way in would throw away
 * sub-cent precision before anything had a chance to sum it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_daily_metrics', function (Blueprint $table): void {
            $table->id();

            // The **UTC** day the figure is about. A date column rather than a timestamp: the
            // platform has no timezone of its own, every operator reads the panel in UTC, and a
            // rollup cut in anything else could not be compared with a ledger stored in UTC.
            $table->date('date');

            // A `PlatformMetric` value. No magic strings (CLAUDE.md §2); an unknown value is
            // dropped on read rather than guessed at, so a renamed case cannot resurrect as a
            // figure nobody can explain.
            $table->string('metric', 48);

            // The breakdown this row is for — a plan key, a provider, a model, a currency, a
            // `t<id>` team reference, or `''` for the undimensioned total.
            //
            // 191 rather than 64 because two of the dimensions are free-form strings somebody else
            // chose: `ai_requests.model` and `prompt_key`. At 64 a long model name would make
            // MySQL in strict mode reject the whole day's insert and take every other dimension
            // with it. The store truncates to this width as well, so the column is a guarantee
            // rather than a hope.
            $table->string('dimension', 191)->default('');

            $table->bigInteger('value')->default(0);

            $table->timestamps();

            // The rollup's upsert target as well as the guarantee.
            $table->unique(['date', 'metric', 'dimension'], 'platform_daily_metrics_point_unique');
            // Every read: one metric across a window, newest last.
            $table->index(['metric', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_daily_metrics');
    }
};
