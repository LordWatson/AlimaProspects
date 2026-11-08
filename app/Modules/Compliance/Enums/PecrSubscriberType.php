<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

use App\Modules\Compliance\Services\SubscriberClassifier;

/**
 * What kind of subscriber a UK prospect is under PECR (PL5) — the thing that decides whether an
 * unsolicited marketing email needs the person's prior consent or only an opt-out.
 *
 * The distinction is PECR's, not ours, and it is the one rule in UK B2B prospecting that most often
 * catches people out: a **corporate** subscriber (a limited company, a PLC, an LLP) may be emailed
 * on a legitimate-interest basis with an opt-out, while an **individual** subscriber — which
 * includes a sole trader and an ordinary partnership, however business-like the address looks — is
 * treated as a consumer and needs prior consent. An address at a one-man plumbing firm is an
 * individual subscriber.
 *
 * **Derived, never stored.** {@see SubscriberClassifier} computes it from what the lead already
 * records — its country, its registration number, its company type. A column would be a second
 * answer that drifts the first time Companies House restates a company type, and the question is
 * cheap to ask.
 *
 * **It warns; it does not block.** Whether to email a sole trader is the customer's decision as
 * controller (`docs/compliance.md` section 5), and a product that refused would be making a legal
 * call on their behalf with less information than they have. So the type is shown, carried into
 * exports and syncs, and recorded — and the send goes through.
 */
enum PecrSubscriberType: string
{
    /** A registered body corporate. Email on a legitimate-interest basis with an opt-out. */
    case Corporate = 'corporate';

    /** A sole trader, a partnership, an individual. Unsolicited marketing needs prior consent. */
    case Individual = 'individual';

    /** Not enough on the record to say — or not a UK prospect, where PECR does not apply. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Corporate => 'Corporate subscriber',
            self::Individual => 'Individual subscriber',
            self::Unknown => 'Subscriber type unknown',
        };
    }

    /**
     * The short note a screen shows beside the badge.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Corporate => 'Marketing email is allowed with a clear opt-out.',
            self::Individual => 'Treated as an individual under PECR — unsolicited marketing email or SMS needs prior consent.',
            self::Unknown => 'Check who the subscriber is before sending unsolicited marketing.',
        };
    }

    /**
     * Whether unsolicited marketing to this subscriber needs the person's prior consent.
     *
     * `Unknown` answers **true**, because the question is "may we proceed without consent" and the
     * honest answer to it, when we do not know, is no. The warning is the product of this, not a
     * refusal — but it fails toward the restriction, the same way `MarketConsentGate` does
     * (`docs/compliance.md` section 5a).
     */
    public function needsPriorConsent(): bool
    {
        return $this !== self::Corporate;
    }

    /**
     * The register a number belonging to this subscriber is screened against.
     *
     * Corporate subscribers are on **CTPS**, everyone else on **TPS**. An unknown subscriber is
     * screened against TPS: it is the register that covers the wider set of people, so asking it is
     * the cautious question rather than the convenient one.
     */
    public function register(): TpsRegister
    {
        return $this === self::Corporate ? TpsRegister::Ctps : TpsRegister::Tps;
    }
}
