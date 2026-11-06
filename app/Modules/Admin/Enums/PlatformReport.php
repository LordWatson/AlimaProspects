<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Services\Platform\PlatformReportBuilder;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Enums\ReportKey;

/**
 * The platform's own reports (Phase 19c) — every one of which is also a CSV download.
 *
 * **Deliberately a separate enum from {@see ReportKey}.** Reporting's keys are a tenant's
 * questions, its builders are scoped by design, and each of its figures has a cohort rule written
 * for one team's window. Adding `margin` to that enum would have been the shortcut, and it would
 * have put a cross-tenant figure inside a module whose entire contract is that it answers for one
 * tenant — then leaked it into `ReportKey::tabular()`, the dashboard's tabs and the API's
 * validation rules, all of which a customer reads.
 *
 * What *is* shared is the plumbing: {@see ReportColumn},
 * `ReportRow` and `ReportCsvExporter` are reused as they stand, so a platform report and a tenant
 * report are written to CSV by the same code and formatted on screen by the same component. One
 * table shape, two sets of questions.
 *
 * Values are stable storage keys — they go in a URL, a filename and an audit entry.
 */
enum PlatformReport: string
{
    case Growth = 'growth';

    case Funnel = 'funnel';

    case Revenue = 'revenue';

    case Movement = 'movement';

    case Retention = 'retention';

    case Pricing = 'pricing';

    case Cost = 'cost';

    case AiCost = 'ai_cost';

    case LeadCost = 'lead_cost';

    case Margin = 'margin';

    case MarginByPlan = 'margin_by_plan';

    case MarginByCohort = 'margin_by_cohort';

    case Outreach = 'outreach';

    /**
     * Which library presets anybody runs, and how far their signals get (S15 task 6).
     *
     * The report that decides where the next pack comes from — and the clearest example in the
     * panel of a question no tenant has ever asked.
     */
    case SignalLibrary = 'signal_library';

    /** Where it is worth looking at all: signals by the source that found them (S15 task 6). */
    case SignalSources = 'signal_sources';

    public function label(): string
    {
        return match ($this) {
            self::Growth => 'Growth',
            self::Funnel => 'Onboarding funnel',
            self::Revenue => 'Revenue by plan',
            self::Movement => 'MRR movement',
            self::Retention => 'Retention by signup cohort',
            self::Pricing => 'Allowance consumption',
            self::Cost => 'Provider health and spend',
            self::AiCost => 'AI spend',
            self::LeadCost => 'Prospects and cost per prospect',
            self::Margin => 'Gross margin per tenant',
            self::MarginByPlan => 'Gross margin by plan',
            self::MarginByCohort => 'Gross margin by signup cohort',
            self::Outreach => 'Outreach volume and deliverability',
            self::SignalLibrary => 'Signal library adoption',
            self::SignalSources => 'Signals by source',
        };
    }

    /** What the report answers, shown under its title and written into the CSV's caption. */
    public function caption(): string
    {
        return match ($this) {
            self::Growth => 'Signups, trials, activations and conversions in this window, against the window before it.',
            self::Funnel => 'Of the tenants that signed up in this window, how far each got. The single report that says whether the product\'s first hour works.',
            self::Revenue => 'Monthly and annual recurring revenue by plan, as at the last day of the window that was rolled up.',
            self::Movement => 'Where the change in MRR over this window came from. New and expansion are positive; contraction and churn are negative.',
            self::Retention => 'For each month\'s signups, how many ever paid and how many still do.',
            self::Pricing => 'How close tenants are to their allowances. The shape of this distribution is what says a plan is priced or sized wrong.',
            self::Cost => 'Calls, failures and spend per external provider over the window. The breaker\'s live state is on the provider-health screen, not here.',
            self::AiCost => 'What Alima spent on AI in this window, by model and by the work the call did.',
            self::LeadCost => 'Prospects sourced by provider, and what each one cost us to find.',
            self::Margin => 'What each tenant pays against what its AI and data calls cost. A tenant can be on Scale and still be unprofitable.',
            self::MarginByPlan => 'The same figures aggregated by the plan in force, which is what says whether a plan is priced right.',
            self::MarginByCohort => 'The same figures aggregated by the month the tenant signed up, which is what says whether the ones we are winning now are better or worse.',
            self::Outreach => 'Platform send volume and deliverability by channel. A shared sending domain means one tenant\'s behaviour is every tenant\'s deliverability.',
            self::SignalLibrary => 'Which library presets tenants actually run, and how far the signals they find get. “Own” is a type a team built themselves — a library nobody starts from is a library that is missing something.',
            self::SignalSources => 'Signals found in this window by the source that found them. What this says is where it is worth looking, which is a different question from what to look for.',
        };
    }

    /** The slug in the download's filename — `alima-platform-margin-2026-10-01-to-2026-10-31.csv`. */
    public function slug(): string
    {
        return 'platform-'.str_replace('_', '-', $this->value);
    }

    /**
     * The reports that get a tab of their own on the reporting screen, in tab order.
     *
     * All of them, unlike Reporting's `tabular()`: there is no platform chart that is a projection
     * of a table here — the tiles, the funnel and the two breakdowns are the chart layer, and
     * everything else is a table because it carries five or six measures per row. See
     * {@see PlatformReportBuilder}.
     *
     * @return list<self>
     */
    public static function tabs(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $report): string => $report->value, self::cases());
    }
}
