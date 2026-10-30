<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Enums;

use App\Modules\Reporting\Services\ReportTables;

/**
 * Every report the dashboard can show **and export** (Phase 13).
 *
 * The task is "CSV export of any report", so this enum is the contract that makes "any" a closed
 * set rather than a promise: {@see ReportTables} turns each case into a table, and a case that
 * cannot be turned into one does not compile. A new chart therefore cannot ship unexportable.
 *
 * Values are stable storage keys — they go in a URL, a filename and an audit entry.
 */
enum ReportKey: string
{
    case Kpis = 'kpis';
    case Funnel = 'funnel';
    case Trend = 'trend';
    case Grades = 'grades';
    case Stages = 'stages';
    case Sources = 'sources';
    case SignalTypes = 'signal_types';
    case IcpPerformance = 'icp_performance';
    case TeamActivity = 'team_activity';
    case AiUsage = 'ai_usage';
    case Outreach = 'outreach';

    /** A translation key naming the report. */
    public function label(): string
    {
        return match ($this) {
            self::Kpis => 'Headline numbers',
            self::Funnel => 'Prospecting funnel',
            self::Trend => 'Sourced and qualified over time',
            self::Grades => 'Fit mix',
            self::Stages => 'Pipeline stages',
            self::Sources => 'Lead sources',
            self::SignalTypes => 'Signal event types',
            self::IcpPerformance => 'Ideal customer performance',
            self::TeamActivity => 'Team activity',
            self::AiUsage => 'AI and data usage',
            self::Outreach => 'Outreach performance',
        };
    }

    /** A translation key explaining what the report counts, shown under its title. */
    public function caption(): string
    {
        return match ($this) {
            self::Kpis => 'The figures across the top of your dashboard.',
            self::Funnel => 'Of the prospects sourced in this period, how far each got.',
            self::Trend => 'Prospects sourced in each period, and how many were a strong fit.',
            self::Grades => 'How the prospects sourced in this period graded against your active ideal customer.',
            self::Stages => 'Where the prospects sourced in this period sit on your board now.',
            self::Sources => 'Which provider found the prospects sourced in this period.',
            self::SignalTypes => 'The kinds of trigger event your watches found in this period.',
            self::IcpPerformance => 'How each ideal customer profile graded the prospects sourced in this period.',
            self::TeamActivity => 'What each member did in this period.',
            self::AiUsage => 'What Alima spent on your behalf in this period, by the work it did.',
            self::Outreach => 'How the approaches made in this period went, by channel.',
        };
    }

    /** The slug in the download's filename — `alima-team-activity-2026-10-30.csv`. */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * The reports that get their own tab in the dashboard's performance section — the ones that are
     * tables first rather than a projection of a chart.
     *
     * @return list<self>
     */
    public static function tabular(): array
    {
        return [self::IcpPerformance, self::Outreach, self::TeamActivity, self::AiUsage];
    }
}
