<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use App\Modules\Core\Enums\Capability;

/**
 * A team's plan does not include what was asked for (Phase 19b).
 *
 * In Core rather than in Billing, because the modules that **throw** it — Signals, AI Insights,
 * Enrichment, Business Intelligence — must not have to import Billing to say "not on your plan"
 * (CLAUDE.md §3). The same reasoning that puts `CapabilityGate` in Core.
 *
 * 402 rather than 403, and the distinction is worth keeping: 403 is "you may not", which is a
 * question about the member and is Policies' answer. This is "your plan does not include this",
 * which is a question about the account and has a different fix — somebody upgrades, or asks us.
 */
final class CapabilityException extends DomainException
{
    protected int $status = 402;

    protected string $errorCode = 'core.capability_unavailable';

    public static function unavailable(Capability $capability): self
    {
        $e = new self($capability->refusal());
        $e->details = ['capability' => $capability->value];

        return $e;
    }
}
