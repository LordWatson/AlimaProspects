<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Contracts\SignalQuotaSource;
use App\Modules\Signals\Data\SignalAllowance;

/**
 * The module's own answer to "what may this team spend on Signals": the config floors (S16 task 1).
 *
 * **Permissive about *plans*, not about *money*, and that distinction is the deviation worth
 * reading.** The three stubs this one mirrors are flatly unlimited, and `StubLeadQuotaSource` says
 * so. This one is not: the three standing ceilings it reports are the floors S6, S7 and S10 shipped
 * (`signals.max_watches_per_team`, `signals.max_definitions_per_team`,
 * `signals.watchlist.max_entries`), and the article-fetch cap is S12's.
 *
 * Returning {@see SignalAllowance::unlimited()} here would have made this phase *remove* four
 * ceilings in the name of adding governance — an installation with `BILLING_ENFORCE_PLANS=false`
 * would become one where a script can create two hundred watches. The argument is the one
 * `config/billing.php` already makes about the AI credit allowance: a cost guard every tenant has
 * had since the feature shipped is not a plan feature, and switching plan enforcement off must not
 * silently switch it off too.
 *
 * What it *is* permissive about is the two monthly query allowances and the two switches. Neither
 * existed before this phase, so there is no floor to keep: a deployment with no plans gets
 * unlimited runs and previews (bounded by the per-run ceiling, the per-watch throttle and the
 * per-member rate limits, which is where they were bounded yesterday), and both sub-features on.
 */
final class StubSignalQuotaSource implements SignalQuotaSource
{
    public function allowanceFor(Team $team): SignalAllowance
    {
        return new SignalAllowance(
            maxDefinitions: max(1, (int) config('signals.max_definitions_per_team', 25)),
            maxWatches: max(1, (int) config('signals.max_watches_per_team', 10)),
            maxWatchlistEntries: max(1, (int) config('signals.watchlist.max_entries', 250)),
            // No floor to keep: see the class docblock.
            monthlySourceQueries: null,
            monthlyPreviewQueries: null,
            // Zero in config means "no ceiling configured" (S12), which is this contract's null.
            articleFetchMicros: self::positiveOrNull((int) config('signals.article_fetch.monthly_cap_micros', 0)),
            playbooksEnabled: true,
            webhookSourceEnabled: true,
        );
    }

    private static function positiveOrNull(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }
}
