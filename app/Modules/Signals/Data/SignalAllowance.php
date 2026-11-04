<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Contracts\SignalQuotaSource;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Services\StubSignalQuotaSource;
use Spatie\LaravelData\Data;

/**
 * Everything a team is allowed to spend on Signals, reported by a {@see SignalQuotaSource} and
 * enforced by {@see SignalSpendGuard} (S16 task 1).
 *
 * **`null` means unlimited, everywhere.** The same reading `LeadQuota::$limit` and
 * `PlanData::$seats` already have, and it is deliberately *not* "zero": zero is a real answer that
 * refuses everything.
 *
 * Which leaves the question a plan-backed source has to answer and this class deliberately does
 * not: *a plan that says nothing at all about a limit*. That is a third state, and it is neither
 * null nor a number — so it is settled where the configuration is read
 * (`Billing\Services\PlanSignalQuotaSource`, named in **prose and never imported** — a `use` for a
 * `{@see}` is still a dependency as far as `CLAUDE.md` §3 and `SignalsSeamTest` are concerned, and
 * it is the one that would have made this module's seam a lie while every docblock went on claiming
 * otherwise; found in the S16 review) rather than smuggled into this DTO. A key the plan omits falls back
 * to the module's own floor; a key the plan sets to `null` is a plan declaring "unlimited" and wins
 * over the floor. Collapsing the two is how an Enterprise plan that spells out `'max_watches' =>
 * null` would have been silently held to the module's ten.
 *
 * The eight figures are three different kinds of thing and they are enforced in three different
 * places, which is worth knowing before adding a ninth:
 *
 * - **Standing ceilings** (`maxDefinitions`, `maxWatches`, `maxWatchlistEntries`) bound how many
 *   *instructions to spend* a team may keep. Enforced on creation, because a watch, a type and an
 *   account on a list each cost money on every run thereafter — which is why the per-watch run
 *   throttle does nothing about them.
 * - **Monthly allowances** (`monthlySourceQueries`, `monthlyPreviewQueries`, `articleFetchMicros`)
 *   bound what a team may spend in a calendar month, measured over this module's own ledgers
 *   (`signal_runs`, `signal_previews`, `signals.article_fetched_at`) on the **UTC calendar month**
 *   Billing's allowances run on (ADR 0012), so a team reading their bill and a team meeting a
 *   ceiling are talking about the same period.
 * - **Switches** (`playbooksEnabled`, `webhookSourceEnabled`) bound whether a team may **take up**
 *   a sub-feature: a playbook cannot be created or switched to live, an events-API credential
 *   cannot be minted, an event cannot be accepted, and a signal type cannot name the webhook as a
 *   source. They are **not** `Core\Enums\Capability` cases on purpose: that enum is governed by
 *   "a capability that is not enforced does not get a case", and both of these are enforced here,
 *   where every other Signals limit a plan sets is — adding them to the enum would have put the
 *   same rule in two places and made the shorter list the one somebody reads.
 *
 *   **They bound taking it up rather than switching it off retroactively**, and the two tails are
 *   deliberate (S16 review). A playbook stored before a downgrade is **skipped** by the evaluator
 *   rather than deleted, and a member may still edit it as long as the edit leaves it paused or
 *   rehearsing — a team must always be able to turn something off, and refusing every update
 *   trapped them into deleting it. And an event a customer pushed while they were entitled to is
 *   still read by the next run inside the source's seven-day window: they sent it in good faith,
 *   and the acceptance endpoint has already stopped taking new ones.
 */
final class SignalAllowance extends Data
{
    public function __construct(
        /** Signal types the team may keep. Platform presets never count against it. */
        public readonly ?int $maxDefinitions,
        /** Watches the team may keep. */
        public readonly ?int $maxWatches,
        /** Accounts across any one watchlist. */
        public readonly ?int $maxWatchlistEntries,
        /** Paid source queries a team's runs may make this calendar month (UTC). */
        public readonly ?int $monthlySourceQueries,
        /** Paid source queries a team's previews may make this calendar month (UTC). */
        public readonly ?int $monthlyPreviewQueries,
        /** Micro-units a team may spend reading full articles this calendar month (UTC). */
        public readonly ?int $articleFetchMicros,
        public readonly bool $playbooksEnabled,
        public readonly bool $webhookSourceEnabled,
    ) {}

    /**
     * No limits at all.
     *
     * Used by tests and by anything that needs the shape without a team. It is deliberately **not**
     * what {@see StubSignalQuotaSource} returns: the module's own config floors exist because an
     * installation with no Billing must still be bounded, which is the whole argument of this phase.
     */
    public static function unlimited(): self
    {
        return new self(
            maxDefinitions: null,
            maxWatches: null,
            maxWatchlistEntries: null,
            monthlySourceQueries: null,
            monthlyPreviewQueries: null,
            articleFetchMicros: null,
            playbooksEnabled: true,
            webhookSourceEnabled: true,
        );
    }

    /**
     * What is left of a monthly allowance, given what has been used — null when unlimited.
     *
     * Floored at zero, because a team that was moved to a smaller plan mid-month can legitimately
     * be past a ceiling, and "-14 queries left" is not a sentence anything should have to render.
     */
    public static function remaining(?int $limit, int $used): ?int
    {
        return $limit === null ? null : max(0, $limit - $used);
    }
}
