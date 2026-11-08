<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Contracts;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\NoSubscriberType;

/**
 * What kind of subscriber this prospect is for direct-marketing purposes, when a regime that draws
 * that distinction applies (PL5's seam, Compliance's answer).
 *
 * The third contract Compliance reaches this module through, beside {@see LeadInterestGate} and
 * Outreach's `LeadRestrictionResolver` — and for the same reason: Lead Sourcing must not know what
 * PECR is. A sourcing provider's job is to find a company; whether that company is a body corporate
 * or a sole trader is a legal question about the regime it sits in, and the module that holds the
 * legitimate-interest assessment is the module that should answer it.
 *
 * It deliberately returns a **flat array of strings rather than an enum**, which is what lets the
 * contract live here at all: the enum is `Compliance\Enums\PecrSubscriberType` and naming it would
 * be the import this seam exists to avoid. The shape is what a screen, an export column and a CRM
 * field all need, and nothing branches on the value — which is ADR 0018 section 1's test for why it
 * is not an enum on this side of the seam.
 *
 * **Null means "no regime here draws this distinction"** — a UAE lead, a lead with no country —
 * rather than "we do not know", which is a value of its own. A screen shows nothing for null and a
 * warning for the unknown case, because those are genuinely different things to tell somebody.
 *
 * The default ({@see NoSubscriberType}) answers null for everything, so this module works alone.
 */
interface SubscriberTypeResolver
{
    /**
     * @return array{value: string, label: string, guidance: string, needs_consent: bool}|null
     */
    public function subscriberTypeFor(Lead $lead): ?array;
}
