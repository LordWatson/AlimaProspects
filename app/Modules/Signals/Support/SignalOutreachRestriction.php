<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;

/**
 * How a signal may be approached — the one rule, in one place.
 *
 * This resolution used to be private to {@see ConvertSignalToLeadAction},
 * which was right while conversion was the only thing that needed the answer. It no longer is:
 * `SignalResource` has to send it so the convert dialog can **say** what the save is about to
 * apply. The alternative was a second copy of a three-step compliance rule, or a screen guessing
 * from `resolved_market` alone — which is step one of three and would have been wrong for every
 * signal whose article named nowhere.
 *
 * The same reasoning as `LeadSourcing\Support\CompanyNameKey`: a rule the product depends on being
 * consistent lives in exactly one callable, and the places that need it call it.
 *
 * **Resolved in three steps** (ADR 0019 §2):
 *
 * 1. **The signal's own resolved market**, from the jurisdiction the extraction put on it. This is
 *    the answer in the normal case and the whole point of S8 — the restriction follows the
 *    *subject*, because it protects the person being approached, and keying it on the watch made a
 *    London funding round found by a UAE watch carry a PDPL restriction it is not subject to.
 * 2. **The watch's market** — the team's declared regime — when the article named nowhere the place
 *    map knows.
 * 3. **The most restrictive restriction across the watch's regions**, which only ever *adds* one
 *    the first two did not supply. A watch over Dubai and London whose signal names neither gets
 *    the UAE restriction, because that is the more restrictive of the two it could have found, and
 *    the direction to fail in is obvious.
 *
 * A region the business holds no compliance position for contributes nothing — it cannot, and
 * `OutreachRestriction` has exactly one case, specifically about UAE PDPL. Stamping it on a
 * Singapore lead would make Outreach do the right thing for a reason that is false and tell a
 * member a lie about why. Those regions are not offered for that reason (ADR 0019 §3).
 *
 * A **deleted watch** leaves the signal with no watch at all (`nullOnDelete`, S6 migration), in
 * which case steps 2 and 3 have nothing to read and step 1 is all there is. Converted signals
 * survive their watch on purpose, so this is reachable.
 */
final class SignalOutreachRestriction
{
    /**
     * The restriction a lead converted from this signal would carry, or null for none.
     */
    public static function for(Signal $signal): ?OutreachRestriction
    {
        $market = $signal->resolvedMarket();

        if ($market instanceof Market) {
            return $market->outreachRestriction();
        }

        $watch = $signal->watch;

        if (! $watch instanceof SignalWatch) {
            return null;
        }

        return self::mostRestrictive($watch->candidateMarkets());
    }

    /**
     * The strictest restriction among a set of markets, or null when none imposes one.
     *
     * "Strictest" is **not** a hard-coded ordering of the cases: it asks each one whether it allows
     * direct marketing at all, which is the question Outreach asks and the only one that currently
     * distinguishes them. A second restriction added later is ranked by what it forbids rather than
     * by where somebody remembered to put it in a `match`.
     *
     * @param  list<Market>  $markets
     */
    private static function mostRestrictive(array $markets): ?OutreachRestriction
    {
        $strictest = null;

        foreach ($markets as $market) {
            $restriction = $market->outreachRestriction();

            if ($restriction === null) {
                continue;
            }

            if ($strictest === null || (! $restriction->allowsDirectMarketing() && $strictest->allowsDirectMarketing())) {
                $strictest = $restriction;
            }
        }

        return $strictest;
    }
}
