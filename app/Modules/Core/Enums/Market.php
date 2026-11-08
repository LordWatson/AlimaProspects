<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Compliance\Services\MarketConsentGate;
use App\Modules\Core\Models\Team;

/**
 * The trading markets Alima supports (ADR 0007). A team's primary market drives regional defaults
 * — locale/timezone hints, currency, and (later) the compliance regime (UK GDPR/PECR vs UAE
 * PDPL/TDRA). Mirrors the `market` column on {@see Team}; kept as a
 * backed enum so there are no magic strings when onboarding sets the default.
 */
enum Market: string
{
    case Uk = 'uk';
    case Uae = 'uae';

    public function label(): string
    {
        return match ($this) {
            self::Uk => 'United Kingdom',
            self::Uae => 'United Arab Emirates',
        };
    }

    /**
     * The currency that market trades in by default.
     */
    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::Uk => Currency::Gbp,
            self::Uae => Currency::Aed,
        };
    }

    /**
     * The limit this market puts on how a prospect found here may be approached, or null when the
     * market imposes none beyond the team's own lawful basis (ADR 0007).
     *
     * The UK runs on a legitimate-interest basis, assessed per team (the LIA, Compliance), so there
     * is no blanket per-lead restriction to record. The UAE does not: PDPL requires the person's
     * **prior consent** for direct marketing, which nothing a team discovers about someone can
     * supply — so every lead sourced from the UAE carries the restriction, and Outreach honours it.
     */
    public function outreachRestriction(): ?OutreachRestriction
    {
        return match ($this) {
            self::Uk => null,
            self::Uae => OutreachRestriction::UaePriorConsent,
        };
    }

    /**
     * The market a prospect in this country falls under, or null when the country is absent or is
     * not one Alima has taken a legal position on.
     *
     * Null is the honest answer rather than a quiet "no rules apply": Alima trades in two markets
     * (ADR 0007), and a third country means somebody is working outside the assessed scope. Callers
     * decide what to do with that — {@see MarketConsentGate}
     * falls through to the team's own market, the PECR classifier declines to classify at all.
     *
     * Shared because two unrelated rules key on the same mapping and a second copy of it is how
     * "GB" comes to mean the UK in one place and nothing in another.
     */
    public static function forCountry(?string $country): ?self
    {
        return match (mb_strtoupper(trim((string) $country))) {
            'GB', 'UK' => self::Uk,
            'AE' => self::Uae,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $market): string => $market->value, self::cases());
    }
}
