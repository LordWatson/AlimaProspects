<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A vendor that can find a named person's work email address (PL2).
 *
 * The question is narrow on purpose: *what is this person's address at this company*. It does
 * **not** say whether mail will arrive — that is {@see EmailVerificationProvider}, a separate
 * capability with its own waterfall, because the vendors best at finding an address are not the
 * ones best at proving it works.
 */
interface EmailFinder extends EnrichmentProvider
{
    /**
     * Find the address, or return a miss.
     *
     * A provider that finds nothing returns {@see EnrichmentResult::miss()} — **it does not
     * throw.** A miss is the ordinary outcome in a waterfall, and an exception for the ordinary
     * outcome turns orchestration into `try`/`catch`.
     *
     * @throws EnrichmentProviderException when the provider itself failed: unconfigured,
     *                                     unreachable, or answering with something we cannot read
     */
    public function findEmail(PersonQuery $query): EnrichmentResult;
}
