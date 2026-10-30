<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Services\EmailVerifier;
use Spatie\LaravelData\Data;

/**
 * The outcome of verifying one address with the free-tier {@see EmailVerifier}: the normalised
 * email, the strongest check it passed, a soft 0–100 confidence, and whether it reads as a
 * role/business address (info@, sales@) vs a personal one. `isPersonal` drives the compliance
 * preference for business/role data (docs/compliance.md); confidence feeds Scoring, never a claim.
 */
final class EmailVerificationResult extends Data
{
    public function __construct(
        public string $email,
        public EmailVerificationStatus $status,
        public int $confidence,
        public bool $isRoleBased,
        public bool $isPersonal,
    ) {}
}
