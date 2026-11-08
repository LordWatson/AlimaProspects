<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

use App\Modules\Enrichment\Services\EnrichmentWaterfall;

/**
 * What a member asked a run to do (PL3).
 *
 * Four scopes rather than one "enrich this lead", because they cost different amounts and answer
 * different questions, and a member deciding to spend should be deciding about one of them. A
 * people-find is the expensive one that opens the others up; a verify is the cheap one that makes
 * an address worth trusting.
 *
 * {@see self::People} is also the only scope that can run on a lead with no contacts at all, which
 * is why {@see EnrichmentWaterfall} treats it first and separately: the other three need somebody
 * to work on.
 */
enum EnrichmentScope: string
{
    /** Find the decision-makers. The only scope that creates contacts. */
    case People = 'people';

    /** Find an address for the contacts we hold. */
    case Email = 'email';

    /** Find a number for the contacts we hold. */
    case Phone = 'phone';

    /** Check the addresses we already hold. The cheap one. */
    case Verify = 'verify';

    public function label(): string
    {
        return match ($this) {
            self::People => 'Find decision-makers',
            self::Email => 'Find email addresses',
            self::Phone => 'Find phone numbers',
            self::Verify => 'Check email addresses',
        };
    }

    /**
     * The capability this scope spends on.
     */
    public function capability(): ProviderCapability
    {
        return match ($this) {
            self::People => ProviderCapability::PeopleFind,
            self::Email => ProviderCapability::EmailFind,
            self::Phone => ProviderCapability::PhoneFind,
            self::Verify => ProviderCapability::EmailVerify,
        };
    }

    /**
     * Whether this scope works on the contacts a lead already has, rather than creating them.
     *
     * The three that do are no-ops on a lead with no contacts — and a run that charged for a
     * waterfall over an empty list would be a charge for nothing.
     */
    public function needsContacts(): bool
    {
        return $this !== self::People;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
