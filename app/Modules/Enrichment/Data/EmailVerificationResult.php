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
 *
 * **PL2 reuses this for a paid verifier's answer too**, rather than adding a second verification
 * DTO beside it. The question is the same question and the column it fills is the same column; what
 * differs is who answered and what it cost, which is what the three fields at the bottom carry. A
 * second type would have meant every reader asking which kind of verification it was holding, and
 * the one that forgot is the one that shows a verified address as unchecked.
 */
final class EmailVerificationResult extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $email,
        public EmailVerificationStatus $status,
        public int $confidence,
        public bool $isRoleBased,
        public bool $isPersonal,
        // --- PL2: set when a paid verifier produced this, left alone by the free-tier check ---
        /** The provider's config key, so an attempt can be attributed. Null = our own check. */
        public ?string $provider = null,
        /** What the call cost us, in micros (ADR 0026 §3). Zero for the free-tier check. */
        public int $costMicros = 0,
        /** The vendor's own payload, for the attempt record. **Never rendered, never logged.** */
        public array $raw = [],
    ) {}

    /**
     * Whether a paid vendor produced this rather than our own syntax/MX check.
     *
     * Read by the reveal rule (ADR 0029): what a vendor was paid for is masked until a member
     * spends on it, and what we read off a public page is not.
     */
    public function isFromVendor(): bool
    {
        return $this->provider !== null;
    }
}
