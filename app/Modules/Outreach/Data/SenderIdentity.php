<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Outreach\Services\SenderIdentityResolver;
use Spatie\LaravelData\Data;

/**
 * Who a sent message says it is from, and how the recipient stops it (docs/compliance.md §3.3).
 *
 * Both halves are required for a send, and neither is a header the member can edit: sender
 * identification and an unambiguous opt-out are the conditions under which the message may be sent
 * at all. Built by {@see SenderIdentityResolver}; a team that can't produce one gets
 * `OutreachBlockReason::NoSenderIdentity` rather than a message that fails to say who sent it.
 */
final class SenderIdentity extends Data
{
    public function __construct(
        /** The platform's verified envelope address — not the team's domain (see config/outreach.php). */
        public readonly string $fromAddress,
        /** "Acme Ltd via Alima": the trading name, then the platform that transmitted it. */
        public readonly string $fromName,
        /** The member's own address, so a reply reaches a person and not a no-reply box. */
        public readonly string $replyTo,
        public readonly string $replyToName,
        /** The team's legal/trading name, as named in the footer. */
        public readonly string $senderName,
        public readonly ?string $senderWebsite,
        /** Where an opt-out goes. Platform-monitored, because an opt-out must reach global suppression. */
        public readonly string $optOutAddress,
        public readonly ?string $privacyNoticeUrl,
    ) {}
}
