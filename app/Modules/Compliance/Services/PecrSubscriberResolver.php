<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Enums\PecrSubscriberType;
use App\Modules\Core\Enums\Market;
use App\Modules\LeadSourcing\Contracts\SubscriberTypeResolver;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * The real {@see SubscriberTypeResolver} (PL5) — PECR's corporate/individual distinction, flattened
 * to what a screen, an export column and a CRM field all need.
 *
 * Two lines of logic and both are decisions:
 *
 * - **Non-UK leads answer null**, not `unknown`. PECR is a UK instrument; a Dubai prospect is not an
 *   unknown kind of PECR subscriber, the question simply does not arise there — and the UAE answer
 *   is `MarketConsentGate`'s prior-consent restriction, which the same screen already shows. Two
 *   warnings about the same send, one of them about the wrong jurisdiction, is how a member learns
 *   to ignore both.
 * - **`unknown` is returned rather than suppressed.** It warns
 *   ({@see PecrSubscriberType::needsPriorConsent()}), and telling
 *   somebody we cannot tell is the honest answer — most of what a maps provider returns has no
 *   registration number, and a blank badge there would read as "fine to email".
 */
final class PecrSubscriberResolver implements SubscriberTypeResolver
{
    public function __construct(private readonly SubscriberClassifier $classifier) {}

    public function subscriberTypeFor(Lead $lead): ?array
    {
        if (Market::forCountry($lead->country) !== Market::Uk) {
            return null;
        }

        $type = $this->classifier->classify($lead);

        return [
            'value' => $type->value,
            'label' => $type->label(),
            'guidance' => $type->guidance(),
            'needs_consent' => $type->needsPriorConsent(),
        ];
    }
}
