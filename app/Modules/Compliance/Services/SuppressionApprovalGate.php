<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Outreach\Services\SuppressionList;

/**
 * The real {@see ContactApprovalGate} (PL5) — bound over Enrichment's permit-everything default by
 * this module's provider.
 *
 * It is four lines of logic and the whole of PL5's "one gate on every outbound path": a person on
 * the do-not-contact list is not stored as a contact, and nobody is charged to reveal an address
 * they may not use.
 *
 * **It reads `SuppressionList` rather than keeping its own copy**, which is the rule recon.md sets
 * for this phase: `outreach_suppressions` stays the one do-not-contact table, `OutreachGuard`
 * stays the one answer to *may this prospect be approached*, and this is the narrower question in
 * front of both — *may we hold this at all*. A second list here would be a second answer, and the
 * way that fails is an address suppressed for sending and still revealed for a fee.
 *
 * The email check reaches the **domain** too, because `isEmailSuppressed()` does: "stop contacting
 * anyone at this company" has to mean that, including the enrichment that would find the next
 * person there.
 */
final class SuppressionApprovalGate implements ContactApprovalGate
{
    public function __construct(private readonly SuppressionList $suppressions) {}

    public function allowsEmail(Team $team, string $email): bool
    {
        return ! $this->suppressions->isEmailSuppressed($email, (int) $team->getKey());
    }

    public function allowsPhone(Team $team, string $phone): bool
    {
        return ! $this->suppressions->isPhoneSuppressed($phone, (int) $team->getKey());
    }

    public function allowsDomain(Team $team, string $domain): bool
    {
        return ! $this->suppressions->isDomainSuppressed($domain, (int) $team->getKey());
    }
}
