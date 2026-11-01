<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Core\Models\AuditLog;

/**
 * How the cross-tenant audit viewer groups `audit_logs.action` (Phase 19c).
 *
 * The categories exist because of one requirement in the phase: **impersonation sessions and admin
 * reads are first-class, rather than buried among writes.** `audit_logs` is one append-only table
 * holding every tenant's ordinary activity — notes added, stages moved, templates saved — and the
 * handful of entries that matter to an operator reviewing operator behaviour are four in ten
 * thousand. A free-text action filter would find them only if you already knew what to type.
 *
 * Matched on the action's **prefix** rather than enumerated, so a module adding an audited action
 * lands in the right group without touching this enum. {@see self::Other} is the honest catch-all:
 * an action nobody has classified shows up as uncategorised rather than being quietly filed under
 * something that sounds close.
 */
enum AuditCategory: string
{
    /** Starting, stopping and expiring an impersonated session. See `Impersonator`. */
    case Impersonation = 'impersonation';

    /**
     * An operator **reading** a tenant's records — a privacy event, and the reason
     * {@see AdminAudit} audits reads at all.
     */
    case AdminRead = 'admin_read';

    /** Every other operator action: a record changed, a setting flipped, an announcement published. */
    case AdminWrite = 'admin_write';

    /** Retention sweeps, exports, erasures, opt-outs, LIA records. */
    case Compliance = 'compliance';

    /** Plans, subscriptions, the portal. */
    case Billing = 'billing';

    /** Sending, suppression, opt-out — the entries a deliverability question starts from. */
    case Outreach = 'outreach';

    /** Sign-in, tokens, second factors, team membership. */
    case Access = 'access';

    /** A tenant's own work: prospects, ICPs, signals, notes, tasks. */
    case TenantActivity = 'tenant_activity';

    /** Anything no prefix below claims. Shown as such rather than guessed at. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Impersonation => 'Impersonation',
            self::AdminRead => 'Operator reads',
            self::AdminWrite => 'Operator changes',
            self::Compliance => 'Compliance',
            self::Billing => 'Billing',
            self::Outreach => 'Outreach',
            self::Access => 'Access and identity',
            self::TenantActivity => 'Tenant activity',
            self::Other => 'Uncategorised',
        };
    }

    /**
     * The action prefixes this category claims, longest-specific first within the category.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Impersonation => ['admin.impersonation.'],
            self::AdminRead => [AdminAudit::RESOURCE_VIEWED, AdminAudit::RECORD_VIEWED],
            self::AdminWrite => ['admin.'],
            self::Compliance => ['compliance.'],
            self::Billing => ['billing.'],
            self::Outreach => ['outreach.'],
            self::Access => ['api_token.', 'two_factor.', 'team.'],
            self::TenantActivity => [
                'leads.', 'lead_sourcing.', 'icp.', 'signals.', 'insight.', 'scoring.',
                'business_intel.', 'onboarding.', 'notifications.', 'reports.',
            ],
            self::Other => [],
        };
    }

    /**
     * Which category an action belongs to.
     *
     * The order of {@see self::cases()} is load-bearing: `admin.impersonation.started` and
     * `admin.resource.viewed` both begin `admin.`, so the two specific categories are declared
     * before {@see self::AdminWrite} and win. Declaring `AdminWrite` first would have swallowed
     * both of the categories this enum exists for.
     */
    public static function of(string $action): self
    {
        foreach (self::cases() as $category) {
            foreach ($category->prefixes() as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $category;
                }
            }
        }

        return self::Other;
    }

    /**
     * Narrowing an {@see AuditLog} query to this category, as a list of `LIKE` prefixes.
     *
     * The returned patterns contain **no wildcard but the trailing `%`**, and no `ESCAPE` clause is
     * added — the prefixes are literal ASCII from this file, never user input, which is what makes
     * that safe. (A `LIKE ... ESCAPE '\'` written defensively is what MySQL refused in the Signals
     * exporter.)
     *
     * @return list<string>
     */
    public function likePatterns(): array
    {
        return array_map(static fn (string $prefix): string => $prefix.'%', $this->prefixes());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
