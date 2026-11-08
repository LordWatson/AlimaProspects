<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use App\Modules\Enrichment\Services\EmailVerifier;

/**
 * A vendor that can say whether mail sent to an address will arrive (PL2).
 *
 * **Named `EmailVerificationProvider` rather than the plan's `EmailVerifier`** because that name is
 * taken: {@see EmailVerifier} is the shipped free-tier check (syntax, disposable domain, MX), it is
 * still what runs for every lead, and a contract sharing its name would make every import in this
 * module a question about which one was meant.
 *
 * It returns the module's existing {@see EmailVerificationResult}, and a vendor's own statuses are
 * **mapped onto {@see EmailVerificationStatus}** by its adapter rather than stored raw. One
 * vocabulary for the column, whoever filled it in — see that enum's note for why a second one
 * would be worse than a lossy mapping.
 */
interface EmailVerificationProvider extends EnrichmentProvider
{
    /**
     * Verify one address.
     *
     * An address the vendor cannot reach a verdict on comes back as
     * {@see EmailVerificationStatus::Unverifiable}, not as an exception: "I don't know" is an
     * answer, and it is one a waterfall can act on by trying the next verifier.
     *
     * @throws EnrichmentProviderException when the provider itself failed
     */
    public function verifyEmail(string $email): EmailVerificationResult;
}
