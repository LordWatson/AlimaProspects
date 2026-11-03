<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Enums\LeadSource;

/**
 * The external services Alima depends on, as the provider-health screen sees them (Phase 19c).
 *
 * **The honest part of this enum is {@see self::breakerPrefix()} returning null.** Three of the
 * seven have no {@see CircuitBreaker} at all — the AI transport, the mail transport and Stripe —
 * and the panel says so rather than drawing a green light nobody wired up. A health screen that
 * reports "closed" for a provider it is not watching is worse than one that reports nothing,
 * because an operator believes it.
 *
 * Where a breaker does exist, this enum carries the cache **prefix and key** each owning module
 * chose. Admin reads them rather than owning them: the breaker belongs to Lead Sourcing, Signals
 * and Notifications respectively, and a prefix declared twice is a reset that clears the wrong
 * entry and leaves the real one open. Keeping both halves here, next to each other, is what makes
 * that checkable — and `PlatformProviderTest` asserts each one still matches the module that
 * writes it.
 *
 * The breaker is **cache-backed and advisory by design** (see its own note): state can evaporate
 * when the cache is cleared, and it sheds load rather than guaranteeing anything. The screen
 * reports it as advisory, never as truth.
 */
enum PlatformProvider: string
{
    case CompaniesHouse = 'companies_house';

    case GooglePlaces = 'google_places';

    /** Serper, behind Lead Sourcing's web-search provider (ADR 0010). */
    case WebSearch = 'web_search';

    /** Serper's /news endpoint, behind Signals' own client and its own breaker (ADR 0011). */
    case SerperNews = 'signals_news';

    /**
     * Serper's /search endpoint **as Signals uses it** (S13, ADR 0023) — distinct from
     * {@see self::WebSearch}, which is Lead Sourcing's use of the same vendor.
     *
     * Two cases for one endpoint because they are two breakers: Lead Sourcing's lives under
     * `lead_sourcing:cb` and Signals' under `signals:cb`, by the same decision that gave Signals its
     * own news breaker at ADR 0011 — the two features are tuned independently and one must not shed
     * the other's load. A single row here would report whichever was reset last.
     */
    case SignalsWebSearch = 'signals_web_search';

    /** Firebase Cloud Messaging, for mobile push. */
    case Push = 'fcm';

    /** Anthropic, through `AiClient` (ADR 0005). */
    case Claude = 'claude';

    /** The mail transport. */
    case Resend = 'resend';

    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'Companies House',
            self::GooglePlaces => 'Google Places',
            self::WebSearch => 'Serper (web search)',
            self::SerperNews => 'Serper (news)',
            self::SignalsWebSearch => 'Serper (web search, Signals)',
            self::Push => 'Firebase push',
            self::Claude => 'Claude',
            self::Resend => 'Resend',
            self::Stripe => 'Stripe',
        };
    }

    /** What stops working for a tenant when this provider is down. */
    public function impact(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'UK discovery runs fail. Google Places and web search are unaffected.',
            self::GooglePlaces => 'UAE and local discovery runs fail.',
            self::WebSearch => 'Web-search discovery runs fail.',
            self::SerperNews => 'Signal watches find nothing; scheduled runs record a failure and retry on the next tick.',
            self::SignalsWebSearch => 'Signal types that run on web search contribute nothing. Their news queries are unaffected — that is why the two breakers are separate.',
            self::Push => 'Mobile push is dropped. In-app and email notifications still arrive.',
            self::Claude => 'Scoring, drafting, insights and the background check stop. Discovery still finds companies; they arrive unscored.',
            self::Resend => 'Outreach sends and notification emails fail. Nothing is silently marked sent — a failed send is recorded as failed.',
            self::Stripe => 'Checkout, the portal, plan changes and metered overage stop. Nobody loses their plan: entitlement is resolved from the grant or the subscription row, not from Stripe being reachable.',
        };
    }

    /**
     * The cache key prefix the owning module gave its breaker, or **null when this provider has no
     * breaker at all** — which is a fact about the code, not a gap in this enum.
     */
    public function breakerPrefix(): ?string
    {
        return match ($this) {
            self::CompaniesHouse, self::GooglePlaces, self::WebSearch => 'lead_sourcing:cb',
            self::SerperNews, self::SignalsWebSearch => 'signals:cb',
            self::Push => 'notifications:cb',
            // No breaker. The AI transport retries and fails the call; mail fails the send and the
            // message is recorded as failed; Cashier surfaces Stripe's own errors. Adding breakers
            // to these is a Phase 20 hardening question, not something to pretend about here.
            self::Claude, self::Resend, self::Stripe => null,
        };
    }

    /**
     * The key *within* that prefix. For the three lead providers it is the {@see LeadSource} value,
     * which is what `AbstractHttpLeadSourceProvider` passes; the other two are the constants their
     * clients declare.
     */
    public function breakerKey(): ?string
    {
        return $this->breakerPrefix() === null ? null : $this->value;
    }

    /**
     * How the rollup records this provider's calls and cost — the `dimension` under
     * {@see PlatformMetric::ProviderCalls} and friends.
     *
     * Identical to the case value today, and a method rather than an assumption because the AI
     * ledger's rows are grouped by *model* and have to be folded onto one provider: every row in
     * {@see AiRequest} is a Claude call, whichever model served it.
     */
    public function metricDimension(): string
    {
        return $this->value;
    }

    /**
     * Whether the rollup has call, failure and cost figures for this provider at all.
     *
     * Resend and Stripe have no ledger of their own in this product — a failed send is recorded on
     * the `outreach_messages` row and a Stripe error on the subscription — so their rows on the
     * health screen carry the breaker column and nothing else, and say why.
     *
     * **{@see self::SignalsWebSearch} is the same answer for a different reason** (S13). Its breaker
     * *is* watched, so the health column is real. But `platform_daily_metrics` records signal
     * provider calls and cost **per run**, attributed to {@see self::SerperNews}, and a run now
     * spans sources — so there is no per-source figure in that ledger to report here, and inventing
     * one by re-deriving it from `signal_runs.source_breakdown` would change what a platform metric
     * means, which is ADR 0016's decision and not S13's. The per-source cost is recorded, and it is
     * shown where cost questions are asked: `/admin/signal-runs`. So this row carries health and
     * says nothing about spend, rather than reporting a zero that is not true.
     */
    public function isMetered(): bool
    {
        return match ($this) {
            self::Resend, self::Stripe, self::Push, self::SignalsWebSearch => false,
            default => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }
}
