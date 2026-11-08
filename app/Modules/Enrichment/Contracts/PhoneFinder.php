<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A vendor that can find a named person's phone number (PL2).
 *
 * The most expensive capability and the most compliance-sensitive: a mobile is personal data, in
 * the UK it is covered by TPS/CTPS screening (PL5), and in the UAE it may not be called at all
 * without prior consent (ADR 0019). None of which is this contract's business — it finds a number
 * and says what **kind** it is, and whether that number may be rung is `OutreachGuard`'s answer
 * (ADR 0027 §6).
 */
interface PhoneFinder extends EnrichmentProvider
{
    /**
     * Find a number, or return a miss.
     *
     * The result's {@see PhoneType} matters more here than anywhere else in the module: a
     * switchboard is a company's published number and a mobile belongs to a person, and the two are
     * not the same thing legally or commercially. A vendor that does not say returns
     * {@see PhoneType::Unknown}, which is read as a person's — the cautious direction.
     *
     * The value comes back as the vendor gave it. Normalising to E.164 is the caller's job (PL3),
     * because it needs a country to do it with and this contract may not have one.
     *
     * @throws EnrichmentProviderException when the provider itself failed
     */
    public function findPhone(PersonQuery $query): EnrichmentResult;
}
