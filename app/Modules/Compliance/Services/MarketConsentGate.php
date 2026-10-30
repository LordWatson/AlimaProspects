<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\RecordedLeadRestriction;

/**
 * The market-aware outreach consent gate (Phase 15, docs/compliance.md §2 / ADR 0007) — bound over
 * Outreach's {@see RecordedLeadRestriction} by the Compliance module provider.
 *
 * It closes the hole the recorded flag left. Signals stamps
 * `leads.raw.outreach_restriction` on the leads it converts, but a company sourced from a UAE
 * business listing by Lead Sourcing carries no flag at all — so before this gate, a team could cold
 * email a Dubai prospect that UAE PDPL requires prior consent to approach. The flag was never meant
 * to be the whole rule; it was the part one module happened to know.
 *
 * **Three inputs, strictest wins** (and the answer can only get stricter, never looser —
 * docs/compliance.md §5a):
 *
 * 1. **The flag recorded on the lead.** Always honoured. Whoever created the lead knew something
 *    about how it was found that nothing here can reconstruct.
 * 2. **The prospect's own jurisdiction**, when the lead says where it is: `leads.country`. This is
 *    the primary rule — the restriction protects the person being approached, and the regime that
 *    protects them is the one where they are.
 * 3. **The team's market**, only when the lead's country is unknown. A presumption rather than a
 *    fact, and the stricter one by design (§2: default to the stricter rule when a prospect's
 *    jurisdiction may be the UAE). A UAE-market team sourcing with no country on the record is
 *    overwhelmingly sourcing in the UAE.
 *
 * Note what (2) deliberately does **not** do: it never *lifts* a restriction because the company is
 * registered elsewhere. That is the Signals deviation written down in §5a — keying on the company's
 * country dropped the restriction the moment a Dubai founder's lead was filed under a UK holding
 * company, which is precisely the case it exists for. Here, a known UK country means UK *adds*
 * nothing; it cannot remove what (1) or a stricter reading already found.
 *
 * **What this gate does not decide:** whether consent or a prior relationship exists for a
 * particular person. Recording that is post-MVP and needs legal sign-off on what evidence suffices
 * (docs/compliance.md §5 ⚖️). Until then a restricted lead keeps its non-marketing channels —
 * LinkedIn and a call script — which {@see OutreachGuard} leaves open precisely so the restriction
 * narrows the approach rather than ending it.
 */
final class MarketConsentGate implements LeadRestrictionResolver
{
    public function __construct(private readonly RecordedLeadRestriction $recorded) {}

    public function restrictionFor(Team $team, Lead $lead): ?OutreachRestriction
    {
        // 1. Whatever is already on the lead stands.
        $restriction = $this->recorded->restrictionFor($team, $lead);

        if ($restriction !== null) {
            return $restriction;
        }

        // 2. The prospect's own jurisdiction, when the record states one.
        $jurisdiction = $this->marketOfCountry($lead->country);

        if ($jurisdiction !== null) {
            return $jurisdiction->outreachRestriction();
        }

        // 3. No country on the record: presume the team's own market.
        return $this->teamMarket($team)->outreachRestriction();
    }

    /**
     * The regime a prospect in this country falls under, or null when the country is absent or not
     * one of the markets Alima supports.
     *
     * Null is the honest answer for an unsupported country, not a quiet "no restriction": the
     * caller falls through to the team's market rather than treating "France" as cleared. Alima
     * trades in two markets (ADR 0007) and a third country means somebody is working outside the
     * assessed scope, which is the team's judgement to make and the LIA's to cover.
     */
    private function marketOfCountry(?string $country): ?Market
    {
        $code = mb_strtoupper(trim((string) $country));

        return match ($code) {
            'GB', 'UK' => Market::Uk,
            'AE' => Market::Uae,
            default => null,
        };
    }

    /**
     * The team's market, defaulting to the UK on an unrecognised column value. The default is the
     * *less* restrictive of the two on purpose: an unreadable market must not silently close every
     * email channel in the product, which would look like a bug rather than a rule. A team actually
     * trading in the UAE has `market` set, and the per-lead country in (2) is the rule that
     * protects the prospect either way.
     */
    private function teamMarket(Team $team): Market
    {
        return Market::tryFrom($team->market) ?? Market::Uk;
    }
}
