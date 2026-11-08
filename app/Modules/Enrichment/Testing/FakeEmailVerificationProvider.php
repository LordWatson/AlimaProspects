<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Testing;

use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Contracts\EmailVerificationProvider;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use App\Modules\Enrichment\Services\EmailVerifier;

/**
 * A deterministic {@see EmailVerificationProvider} for tests and local demo seeding (PL2).
 *
 * Queue a verdict per call. The default for an empty queue is
 * {@see EmailVerificationStatus::Unverifiable} rather than `Verified`, deliberately: a fake whose
 * default was the best possible outcome would make a waterfall test pass without ever exercising
 * the fall-through, and "we could not tell" is what a real verifier says more often than anybody
 * expects.
 */
final class FakeEmailVerificationProvider implements EmailVerificationProvider
{
    /** @var list<EmailVerificationStatus|EnrichmentProviderException> */
    private array $queue = [];

    /** @var list<string> */
    public private(set) array $verified = [];

    public function __construct(
        private readonly string $key = 'fake_verifier',
        private readonly bool $enabled = true,
        private readonly CostProfile $cost = new CostProfile,
    ) {}

    public function willReturn(EmailVerificationStatus $status): self
    {
        $this->queue[] = $status;

        return $this;
    }

    public function willFail(?EnrichmentProviderException $exception = null): self
    {
        $this->queue[] = $exception ?? EnrichmentProviderException::unavailable($this->key);

        return $this;
    }

    public function verifyEmail(string $email): EmailVerificationResult
    {
        $this->verified[] = $email;

        $next = array_shift($this->queue);

        if ($next instanceof EnrichmentProviderException) {
            throw $next;
        }

        $status = $next ?? EmailVerificationStatus::Unverifiable;
        $normalised = ContactIdentity::email($email) ?? $email;

        // Borrowed from the free-tier checker rather than reimplemented: `isRoleBased()` is a pure
        // config lookup, so a fake can use the real rule without touching DNS, and a second copy of
        // "what counts as info@" is a second answer waiting to disagree.
        $isRoleBased = app(EmailVerifier::class)->isRoleBased($normalised);

        return new EmailVerificationResult(
            email: $normalised,
            status: $status,
            confidence: $status->confidence(),
            isRoleBased: $isRoleBased,
            isPersonal: ! $isRoleBased,
            provider: $this->key,
            costMicros: $this->cost->forOutcome(true),
        );
    }

    public function callCount(): int
    {
        return count($this->verified);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function capability(): ProviderCapability
    {
        return ProviderCapability::EmailVerify;
    }

    public function describesCost(): CostProfile
    {
        return $this->cost;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
