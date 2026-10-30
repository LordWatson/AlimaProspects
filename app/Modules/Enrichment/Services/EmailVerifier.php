<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Contracts\DnsResolver;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use Illuminate\Support\Str;

/**
 * Free-tier email verification (Phase 8, ADR 0008): syntax → disposable-domain → MX/DNS, with no
 * SMTP probe. It returns a {@see EmailVerificationResult} carrying the strongest check the address
 * passed and a soft confidence — it NEVER asserts deliverability, only routability. It also
 * classifies the address as role/business vs personal, so the pipeline can prefer business data and
 * flag personal data for compliance (docs/compliance.md).
 */
final class EmailVerifier
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function verify(string $email): EmailVerificationResult
    {
        $email = Str::lower(trim($email));

        $isRoleBased = $this->isRoleBased($email);

        // A role/business mailbox is never treated as "personal data"; anything else that looks like
        // a real address is, so compliance can prefer business/role data.
        $status = $this->status($email);
        $isPersonal = ! $isRoleBased
            && $email !== ''
            && $status !== EmailVerificationStatus::InvalidSyntax;

        return new EmailVerificationResult(
            email: $email,
            status: $status,
            confidence: $status->confidence(),
            isRoleBased: $isRoleBased,
            isPersonal: $isPersonal,
        );
    }

    private function status(string $email): EmailVerificationStatus
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return EmailVerificationStatus::InvalidSyntax;
        }

        $domain = $this->domain($email);
        if ($domain === null) {
            return EmailVerificationStatus::InvalidSyntax;
        }

        if ($this->isDisposable($domain)) {
            return EmailVerificationStatus::DisposableDomain;
        }

        return $this->dns->hasMailExchanger($domain)
            ? EmailVerificationStatus::MxFound
            : EmailVerificationStatus::NoMxRecord;
    }

    private function domain(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $domain = substr($email, $at + 1);

        return $domain === '' ? null : $domain;
    }

    private function localPart(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
    }

    /**
     * Whether an address is a role/business mailbox (`info@`, `sales@`) rather than a person's.
     *
     * Public, and the one part of this class that touches nothing but config: a caller that is
     * only classifying an address — the add-a-lead form, say — can ask without triggering the DNS
     * lookup {@see self::verify()} does, and without keeping a second copy of the role list.
     */
    public function isRoleBased(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        /** @var list<string> $roles */
        $roles = config('enrichment.role_local_parts', []);

        return in_array($this->localPart($email), array_map('strval', $roles), strict: true);
    }

    private function isDisposable(string $domain): bool
    {
        /** @var list<string> $disposable */
        $disposable = config('enrichment.disposable_domains', []);

        return in_array($domain, array_map(static fn (string $d): string => Str::lower($d), $disposable), strict: true);
    }
}
