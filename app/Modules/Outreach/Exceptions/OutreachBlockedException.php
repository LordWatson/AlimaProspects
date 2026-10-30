<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Exceptions;

use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Services\OutreachGuard;

/**
 * {@see OutreachGuard} refused the channel (Phase 11).
 *
 * It carries the whole decision rather than a message, so the caller's error envelope can name
 * every rule that fired and the audit entry can record which one it was. 422 rather than 403: the
 * member is allowed to be here and allowed to act — it is *this lead on this channel* that is not
 * available, which is a fact about the request rather than about their permissions.
 */
final class OutreachBlockedException extends OutreachException
{
    protected string $errorCode = 'outreach_blocked';

    private OutreachDecision $decision;

    public static function because(OutreachDecision $decision): self
    {
        $reason = $decision->primaryReason();

        $exception = new self($reason === null
            ? __('This lead cannot be approached on that channel.')
            // A translation key, not a finished string — the enum holds the key (EN/AR, ADR 0007).
            : __($reason->guidance()));
        $exception->decision = $decision;
        // A compliance refusal is final and a configuration one is fixable, and the code is how an
        // API client tells them apart without parsing prose.
        $exception->errorCode = $decision->isComplianceBlock()
            ? 'outreach_blocked_compliance'
            : 'outreach_blocked_configuration';

        return $exception;
    }

    public function decision(): OutreachDecision
    {
        return $this->decision;
    }

    /**
     * @return list<OutreachBlockReason>
     */
    public function reasons(): array
    {
        return $this->decision->reasons;
    }
}
