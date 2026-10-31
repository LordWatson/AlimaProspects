<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Auth\Http\Middleware\EnsureTokenAbility;

/**
 * What a personal access token is allowed to do on `/api/v1` (Phase 17).
 *
 * Sanctum has stored abilities on every token since the first phase, but nothing ever checked
 * them: every token was effectively `*`, so "scope this integration to read-only" was a promise
 * the API could not keep. These are the scopes, one read/write pair per resource group, and
 * {@see EnsureTokenAbility} is what enforces them.
 *
 * **Deliberately coarse.** A scope per endpoint would be unusable in a picker and would make every
 * new endpoint a breaking change for existing tokens; a scope per resource group is the unit a
 * person can reason about ("let it read my leads, not send my email").
 *
 * Two kinds of route carry **no** ability. Identity (`GET user`) and a token's own management
 * (`auth/tokens*`) are not scopable: a token that cannot say who it belongs to or revoke itself is
 * a token nobody can clean up safely.
 */
enum TokenAbility: string
{
    case LeadsRead = 'leads:read';
    case LeadsWrite = 'leads:write';
    case DiscoveryRead = 'discovery:read';
    case DiscoveryWrite = 'discovery:write';
    case IcpRead = 'icp:read';
    case IcpWrite = 'icp:write';
    case ScoringRead = 'scoring:read';
    case ScoringWrite = 'scoring:write';
    case OutreachRead = 'outreach:read';
    case OutreachWrite = 'outreach:write';
    case SignalsRead = 'signals:read';
    case SignalsWrite = 'signals:write';
    case InsightsRead = 'insights:read';
    case InsightsWrite = 'insights:write';
    case NotificationsRead = 'notifications:read';
    case NotificationsWrite = 'notifications:write';
    case ReportsRead = 'reports:read';
    case TeamRead = 'team:read';
    case TeamWrite = 'team:write';

    /**
     * The wildcard Sanctum issues by default, and what an unscoped token carries.
     */
    public const string WILDCARD = '*';

    /**
     * Every ability plus the wildcard — what a token may legally ask for.
     *
     * @return list<string>
     */
    public static function issuable(): array
    {
        return [self::WILDCARD, ...array_map(fn (self $ability): string => $ability->value, self::cases())];
    }

    /**
     * The resource group this ability scopes, e.g. `leads`.
     */
    public function group(): string
    {
        return explode(':', $this->value)[0];
    }

    /**
     * What a person sees next to the checkbox when they scope a token.
     */
    public function label(): string
    {
        return match ($this) {
            self::LeadsRead => __('Read leads, contacts and the pipeline'),
            self::LeadsWrite => __('Add leads, move stages, write notes and tasks'),
            self::DiscoveryRead => __('Read discovery runs'),
            self::DiscoveryWrite => __('Start discovery runs'),
            self::IcpRead => __('Read ideal customer profiles'),
            self::IcpWrite => __('Create and change ideal customer profiles'),
            self::ScoringRead => __('Read lead scores'),
            self::ScoringWrite => __('Re-score leads and give scoring feedback'),
            self::OutreachRead => __('Read messages, templates and contact attempts'),
            self::OutreachWrite => __('Draft and send outreach, and log attempts'),
            self::SignalsRead => __('Read signals and watches'),
            self::SignalsWrite => __('Convert and dismiss signals, and run watches'),
            self::InsightsRead => __('Read AI insights'),
            self::InsightsWrite => __('Dismiss AI insights'),
            self::NotificationsRead => __('Read notifications'),
            self::NotificationsWrite => __('Mark notifications read and register devices'),
            self::ReportsRead => __('Read the dashboard and reports'),
            self::TeamRead => __('Read teams and setup state'),
            self::TeamWrite => __('Create teams and switch the current one'),
        };
    }

    /**
     * The abilities grouped by resource, in the order a picker should show them.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function catalogue(): array
    {
        $grouped = [];

        foreach (self::cases() as $ability) {
            $grouped[$ability->group()][] = [
                'value' => $ability->value,
                'label' => $ability->label(),
            ];
        }

        return $grouped;
    }
}
