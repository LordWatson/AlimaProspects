<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Enums\PecrSubscriberType;
use App\Modules\Core\Enums\Market;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Str;

/**
 * Which kind of PECR subscriber a lead is (PL5, `docs/compliance.md` section 3.15).
 *
 * Pure and cheap: it reads three columns the lead already has and returns a
 * {@see PecrSubscriberType}. Nothing is stored, because a column would be a second answer that
 * drifts the first time Companies House restates a company type.
 *
 * **Only one path reaches `Corporate`**, and that is the design rather than caution for its own
 * sake: a UK lead with a registration number **and** a company type that names a body corporate.
 * Everything else warns, including a lead with no registration number at all — which is most of
 * what Google Places returns. The temptation is to read "no company number" as "sole trader" and
 * label it `Individual`; it is not the same claim. A lead can lack a number because nobody matched
 * it to the register yet, and asserting a subscriber type on that basis would be the product making
 * up a legal fact. `Unknown` warns exactly as `Individual` does
 * ({@see PecrSubscriberType::needsPriorConsent()}) and says only what we know.
 *
 * **Non-UK leads are `Unknown`, not `Corporate`.** PECR is a UK instrument; a Dubai company is not
 * a corporate subscriber under it, it is outside it — and the UAE answer is a different question
 * that `MarketConsentGate` already settles (ADR 0019). Returning `Corporate` for a UAE lead would
 * read on screen as "marketing email is allowed", which is the opposite of the truth there.
 */
final class SubscriberClassifier
{
    /**
     * Companies House company types that are bodies corporate — a subscriber PECR treats as
     * corporate.
     *
     * Matched as a **prefix set over a normalised key**, because the register's vocabulary is long,
     * hyphenated and still growing (`private-limited-guarant-nsc`, `uk-establishment`), and an
     * exact list would silently reclassify a company the week a new type appeared. A type we do not
     * recognise falls through to `Unknown`, which warns.
     */
    private const array CORPORATE_PREFIXES = [
        'ltd',
        'plc',
        'llp',
        'private-limited',
        'private-unlimited',
        'public-limited',
        'old-public-company',
        'limited-liability-partnership',
        'charitable-incorporated',
        'community-interest-company',
        'royal-charter',
        'registered-society',
        'industrial-and-provident',
        'uk-establishment',
        'eeig',
        'scottish-charitable-incorporated',
    ];

    /**
     * Types that are *not* bodies corporate however they are registered — a partnership is the
     * partners, and PECR treats them as individual subscribers. ⚖️
     *
     * Checked **before** the corporate list, because `limited-partnership` would otherwise match
     * `limited` and a partnership would be labelled corporate — which is exactly the mistake the
     * badge exists to prevent.
     */
    private const array INDIVIDUAL_PREFIXES = [
        'limited-partnership',
        'scottish-partnership',
        'partnership',
        'sole-trader',
        'individual',
    ];

    public function classify(Lead $lead): PecrSubscriberType
    {
        if (Market::forCountry($lead->country) !== Market::Uk) {
            return PecrSubscriberType::Unknown;
        }

        $key = $this->key($lead->company_type);

        if ($key !== null && $this->matches($key, self::INDIVIDUAL_PREFIXES)) {
            // A partnership is a partnership whether or not it holds a registration number.
            return PecrSubscriberType::Individual;
        }

        // A registration number is necessary but not sufficient: a limited partnership has one too.
        if ($this->number($lead->company_number) === null) {
            return PecrSubscriberType::Unknown;
        }

        return $key !== null && $this->matches($key, self::CORPORATE_PREFIXES)
            ? PecrSubscriberType::Corporate
            : PecrSubscriberType::Unknown;
    }

    /**
     * The company type reduced to a comparable key: lower-cased, with spaces and underscores turned
     * into the hyphens the register uses, so "Private Limited Company" and `private-limited`
     * compare equal.
     */
    private function key(?string $companyType): ?string
    {
        $key = Str::lower(trim((string) $companyType));
        $key = (string) preg_replace('/[\s_]+/', '-', $key);

        return $key === '' ? null : $key;
    }

    private function number(?string $companyNumber): ?string
    {
        $number = trim((string) $companyNumber);

        return $number === '' ? null : $number;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matches(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($key === $prefix || Str::startsWith($key, $prefix.'-')) {
                return true;
            }
        }

        return false;
    }
}
