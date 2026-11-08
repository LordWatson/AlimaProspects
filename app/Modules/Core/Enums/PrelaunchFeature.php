<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Models\Team;
use Laravel\Pennant\Feature;

/**
 * The pre-launch feature flags (`pre-launch.md` Appendix A), resolved per **team** through Pennant.
 *
 * They exist so the pre-launch phases can be merged and deployed before they are finished, and
 * switched on one team at a time. Defaults live in `config/prelaunch.php`; a per-team override is a
 * row in `features`, so a pilot is a database write rather than a deploy.
 *
 * **A flag is not a {@see Capability}.** A flag asks *is this code finished enough to be seen* and
 * is temporary; a capability asks *is this part of what this team pays for* and is permanent and a
 * sales artefact. Conflating them means a customer's plan changes when a deploy goes out
 * (ADR 0026 §4). A feature has at most one of each.
 *
 * **Each case is deleted once its feature is live.** A flag left behind after launch is a second
 * code path nobody tests, and the first pre-launch flag is as good a place as any to say so.
 */
enum PrelaunchFeature: string
{
    /** PL1 — dated, sourced evidence behind every lead, with a confidence and a why-now line. */
    case WhyNow = 'prelaunch.why-now';

    /** PL3 — the vendor waterfall, the Contacts panel and the reveal. */
    case Enrichment = 'prelaunch.enrichment';

    /**
     * Whether this flag is on for the given team.
     *
     * Scoped to the team rather than to the user, because every one of these features is a
     * property of a workspace: two members of the same team must not see different lead lists.
     */
    public function activeFor(Team $team): bool
    {
        return Feature::for($team)->active($this->value);
    }

    /**
     * The flag's default answer when no override has been stored — `config/prelaunch.php`, so a
     * flag can also be switched by environment in a deploy that has no database access yet.
     */
    public function default(): bool
    {
        return (bool) config('prelaunch.flags.'.$this->value, false);
    }
}
