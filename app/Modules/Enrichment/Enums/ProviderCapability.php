<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

use App\Modules\Enrichment\Contracts\EmailFinder;
use App\Modules\Enrichment\Contracts\EmailVerificationProvider;
use App\Modules\Enrichment\Contracts\EnrichmentProvider;
use App\Modules\Enrichment\Contracts\PersonFinder;
use App\Modules\Enrichment\Contracts\PhoneFinder;

/**
 * The four things a paid enrichment vendor can be asked to do (PL2).
 *
 * One capability, one contract, one waterfall — which is why this is an enum and the vendors behind
 * it are not: a class exists per capability and the orchestration branches on it, while a vendor is
 * a registry entry with a config key (ADR 0018 §1, the same test ADR 0023 applied to signal
 * sources and ADR 0008 to lead sources).
 *
 * The capabilities are deliberately **narrow**. Several vendors sell "enrichment" as one product
 * that returns a person, their email and their mobile in a single call; buying it that way means
 * paying for three answers to get the one that was wanted, and it means a vendor that is good at
 * mobiles and poor at emails cannot be used for mobiles alone. A vendor that genuinely does two
 * things implements two contracts and appears in two waterfalls.
 */
enum ProviderCapability: string
{
    /** Find a named person's email address at a company. */
    case EmailFind = 'email_find';

    /** Check whether an address will accept mail — the question the free MX check cannot answer. */
    case EmailVerify = 'email_verify';

    /** Find a named person's phone number, mobile for preference. */
    case PhoneFind = 'phone_find';

    /** Find the decision-makers at a company in the first place. */
    case PeopleFind = 'people_find';

    public function label(): string
    {
        return match ($this) {
            self::EmailFind => 'Email finder',
            self::EmailVerify => 'Email verifier',
            self::PhoneFind => 'Phone finder',
            self::PeopleFind => 'Decision-maker finder',
        };
    }

    /**
     * The contract a provider of this capability implements. Used by the registry to refuse a
     * misconfigured entry at boot rather than at the first call — a vendor listed under the wrong
     * capability would otherwise fail inside a queued job, against a real lead, having already
     * been paid.
     *
     * @return class-string<EnrichmentProvider>
     */
    public function contract(): string
    {
        return match ($this) {
            self::EmailFind => EmailFinder::class,
            self::EmailVerify => EmailVerificationProvider::class,
            self::PhoneFind => PhoneFinder::class,
            self::PeopleFind => PersonFinder::class,
        };
    }

    /**
     * The `config/enrichment.php` key holding this capability's ordered provider list.
     */
    public function waterfallKey(): string
    {
        return 'enrichment.waterfall.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }
}
