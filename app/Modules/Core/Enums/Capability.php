<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\CapabilityGate;

/**
 * A feature a team either has or does not, depending on their plan (Phase 19b, ADR 0015).
 *
 * **Name trap, and the reason this enum exists rather than a config key:** `config/billing.php`
 * already has `plans.*.features`, and that is **marketing copy for the pricing page** — sentences
 * like "Signals — trigger-event prospecting". The gateable set is `plans.*.capabilities`, a list of
 * the values below. Overloading the existing key would have turned a sentence into a permission,
 * and the first person to reword the pricing page would have switched a feature off.
 *
 * **The list is deliberately short, and every case on it is actually enforced.** That is the rule
 * this enum is governed by: a capability that is declared but not enforced is worse than one that
 * does not exist, because the pricing page would then promise a gate that is not there and nobody
 * would find out until a customer used a feature they had not bought. {@see self::enforcedAt()}
 * names the enforcement point for each one, and `CapabilityEnforcementTest` asserts that every case
 * has one.
 *
 * Where enforcement happens is decided per capability by **who can trigger it**:
 *
 * - Anything reachable from a queued job or the scheduler is checked in the **guard or Action**,
 *   because there is no request to put middleware on — ADR 0012's argument, unchanged.
 * - Anything only a person can start is checked by the `capability:` route middleware, so a
 *   disabled screen refuses politely instead of half-rendering.
 *
 * Several are checked in both places, which is not redundancy: the route check is what gives the
 * member a sentence they can act on, and the guard check is what makes the refusal true.
 *
 * @see CapabilityGate the one thing anything asks. Never read a plan's capabilities directly.
 */
enum Capability: string
{
    /**
     * Trigger-event prospecting (ADR 0011). A `Team`-plan feature in the price list, and the
     * clearest example of why overrides exist: a Starter customer piloting Signals is a sales
     * conversation, not a plan edit.
     */
    case Signals = 'signals';

    /** The daily AI commentary and call list (Phase 14). */
    case AiInsights = 'ai_insights';

    /** The AI background check on a tenant's own business (Phase 6). */
    case BusinessIntelligence = 'business_intelligence';

    /** Contact discovery and per-field provenance on a lead (Phase 8). */
    case Enrichment = 'enrichment';

    /** Taking the data out: the leads CSV and the reporting export. */
    case CsvExport = 'csv_export';

    /** Issuing and using `/api/v1` tokens (ADR 0013). */
    case ApiAccess = 'api_access';

    public function label(): string
    {
        return match ($this) {
            self::Signals => 'Signals',
            self::AiInsights => 'AI insights',
            self::BusinessIntelligence => 'Business background check',
            self::Enrichment => 'Contact enrichment',
            self::CsvExport => 'CSV export',
            self::ApiAccess => 'API access',
        };
    }

    /**
     * What a member is told when their plan does not include it. Plain, and about the plan rather
     * than about them.
     */
    public function refusal(): string
    {
        return match ($this) {
            self::Signals => 'Signals is not included on your plan.',
            self::AiInsights => 'AI insights are not included on your plan.',
            self::BusinessIntelligence => 'The background check is not included on your plan.',
            self::Enrichment => 'Contact enrichment is not included on your plan.',
            self::CsvExport => 'Exporting is not included on your plan.',
            self::ApiAccess => 'API access is not included on your plan.',
        };
    }

    /**
     * Where this capability is actually enforced. Documentation that a test reads: a case with
     * nothing here is a capability that lies.
     */
    public function enforcedAt(): string
    {
        return match ($this) {
            self::Signals => 'RunSignalWatchAction (queued and scheduled) + the signal-watch routes',
            self::AiInsights => 'InsightGenerator (scheduled) + the insights web and api routes',
            self::BusinessIntelligence => 'RunBackgroundCheckAction (queued) + the business-intel routes',
            self::Enrichment => 'EnrichLeadAction (queued) + the lead-sourcing enrichment route',
            self::CsvExport => 'the leads.export and reports.export routes',
            self::ApiAccess => 'IssueApiTokenAction + capability:api_access on the whole api group',
        };
    }

    /**
     * What a team gets when no plan in the price list declares any capabilities at all.
     *
     * **True**, deliberately, and for the same reason Auth's seat stub is unlimited: an
     * installation whose `config/billing.php` has not been given a `capabilities` key should behave
     * exactly as it did before this enum existed, rather than silently switching six features off
     * for everybody. A missing configuration is not a product decision.
     */
    public function enabledByDefault(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }
}
