<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Services;

use App\Modules\AiInsights\Console\PruneInsightsCommand;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Http\Resources\InsightResource;
use App\Modules\AiInsights\Models\Insight;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of how long an insight is kept (Phase 14).
 *
 * Two callers must not disagree about it: {@see PruneInsightsCommand} deletes rows by it, and
 * {@see InsightResource} tells the member "clears in N days" by it — the same arrangement Signals
 * uses, for the same reason (a screen promising 30 days while the sweep used 7 is worse than
 * saying nothing).
 *
 * Insights are swept harder than anything else in the platform, and are the one thing that can
 * afford to be. They are **derived**: nothing reads them, every row can be rebuilt tomorrow from
 * the leads and the Reporting read-model, and a week-old "contact these five today" is actively
 * misleading rather than merely old. They also carry company names, so there is no reason to hold
 * them past their usefulness (`docs/compliance.md` §5a, data minimisation).
 *
 * A window runs from **when the clock on that status started**: `dismissed_at` for a dismissed
 * insight, `generated_at` for one still open. Measuring a dismissal from `generated_at` instead
 * looks tidier and is wrong in a way a member would notice — an insight cleared on day 40 of its
 * life would be past a 30-day window the moment it was cleared, vanish from the Dismissed tab at
 * the next nightly sweep, and show "clears in 0 days" in the meantime. The tab exists so somebody
 * who cleared the wrong card can go and read it; the window should start when they cleared it.
 *
 * For an open insight the question is what day it describes, which is `generated_at` and not
 * `created_at` — the same instant today, and not the same instant the first time a backfill writes
 * an older one.
 */
final class InsightRetention
{
    /**
     * How many days an insight of this status is kept. Never null: unlike a converted signal,
     * there is no insight worth keeping indefinitely.
     */
    public function windowFor(InsightStatus $status): int
    {
        return match ($status) {
            // The member said no. The audit entry records that they did, which is what tuning the
            // generator reads; the card itself has done its job.
            InsightStatus::Dismissed => max(1, (int) config('ai-insights.retention.dismissed_days', 30)),
            // Never actioned. Kept longer than a dismissal so a member back from a fortnight off
            // can still see what they missed, and no longer.
            InsightStatus::New => max(1, (int) config('ai-insights.retention.unactioned_days', 60)),
        };
    }

    /**
     * When this insight becomes eligible for deletion.
     *
     * Returns `CarbonInterface`, not `Illuminate\Support\Carbon`: the app runs on
     * `CarbonImmutable` (AppServiceProvider), which is a *sibling* of that class rather than a
     * subclass, so the narrower type would be a runtime TypeError PHPStan cannot see.
     */
    public function deletesAt(Insight $insight): CarbonInterface
    {
        return $this->clockStartedAt($insight)->copy()->addDays($this->windowFor($insight->status));
    }

    /**
     * When this insight's retention clock started: the dismissal for a dismissed one, otherwise
     * the generation. Falls back to `generated_at` for the row the database should not contain —
     * dismissed with no timestamp — rather than treating it as immortal.
     */
    private function clockStartedAt(Insight $insight): CarbonInterface
    {
        return $insight->status === InsightStatus::Dismissed && $insight->dismissed_at !== null
            ? $insight->dismissed_at
            : $insight->generated_at;
    }

    /**
     * Whole days until the sweep deletes this insight.
     *
     * Never negative: the sweep runs nightly, so a row can legitimately sit past its window for a
     * few hours, and "clears in -2 days" tells a member nothing.
     */
    public function deletesInDays(Insight $insight): int
    {
        return max(0, (int) ceil(now()->diffInDays($this->deletesAt($insight), absolute: false)));
    }

    /**
     * The rows of `$status` that are past their window, **without the tenant scope**: the sweep is
     * a system job across every team, which is why it is reachable only from the console.
     *
     * @return Builder<Insight>
     */
    public function eligible(InsightStatus $status): Builder
    {
        $cutoff = now()->subDays($this->windowFor($status));

        // The same clock the countdown on screen uses, expressed in SQL: `coalesce` so a dismissed
        // row written without its timestamp still ages out rather than living for ever.
        return Insight::query()
            ->withoutGlobalScopes()
            ->where('status', $status->value)
            ->when(
                $status === InsightStatus::Dismissed,
                fn (Builder $query): Builder => $query->whereRaw('coalesce(dismissed_at, generated_at) < ?', [$cutoff]),
                fn (Builder $query): Builder => $query->where('generated_at', '<', $cutoff),
            );
    }

    /**
     * The statuses the sweep considers, in the order it sweeps them.
     *
     * @return list<InsightStatus>
     */
    public function sweptStatuses(): array
    {
        return InsightStatus::cases();
    }
}
