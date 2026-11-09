<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Contracts\UsageSummarySource;
use Spatie\LaravelData\Data;

/**
 * One of the team's monthly allowances, as the dashboard draws it.
 *
 * Deliberately **flat scalars**, and deliberately the same key set as Billing's own
 * `UsageResource` — the shape `components/billing/UsageBar.vue` already renders. Two consequences,
 * both wanted: the dashboard reuses that component rather than growing a second bar that could
 * disagree with the billing screen's, and nothing in this module has to import
 * `App\Modules\Billing` to describe a plan (CLAUDE.md §3; the same reason
 * `LeadSourcing\Contracts\SubscriberTypeResolver` crosses its seam as strings).
 *
 * Every derived figure is sent rather than computed in the browser, for the reason
 * `UsageResource`'s docblock gives: where a plan stops — allowance or ceiling, depending on
 * whether it meters — is a rule that must not live in two languages.
 *
 * `metric` is a stable storage key, not something this module branches on; `label` is a
 * translation key like every other label here.
 *
 * @see UsageSummarySource for who fills these in.
 */
final class AllowanceLine extends Data
{
    public function __construct(
        public readonly string $metric,
        /** Translation key, not a finished string. */
        public readonly string $label,
        public readonly int $used,
        /** The monthly allowance; null = unlimited, and the bar is not drawn at all. */
        public readonly ?int $included,
        /** Allowance still unspent; null when unlimited. */
        public readonly ?int $remaining,
        /** How much of the allowance is gone, 0–100. */
        public readonly int $used_percent,
        /** Where work stops however much overage is permitted; null = no ceiling. */
        public readonly ?int $ceiling,
        /** Units left before the ceiling; null when there is none. */
        public readonly ?int $headroom,
        /** Units consumed beyond the allowance — zero on a plan that simply stops. */
        public readonly int $overage,
        /** Whether this plan meters past its allowance rather than stopping. */
        public readonly bool $overage_allowed,
        /** Whether further work is refused. */
        public readonly bool $exhausted,
    ) {}
}
