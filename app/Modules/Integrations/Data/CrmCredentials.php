<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Data;

use App\Modules\Integrations\Actions\ConnectIntegrationAction;
use DateTimeInterface;

/**
 * What a provider hands back from an authorisation or a refresh (PL7).
 *
 * The account id and name come back with the tokens rather than being fetched afterwards, because
 * the one thing that must be checked on a reconnect is whether this is the **same CRM account**:
 * reconnecting to a different one with the old `integration_links` still in place would have Alima
 * updating record 4821 in a company that has never heard of us. {@see ConnectIntegrationAction}
 * is where that check lives.
 *
 * `expiresAt` is nullable because some providers issue tokens with no stated life, and inventing
 * one would mean refreshing a perfectly good token on a guess.
 *
 * A plain readonly value object, for the reason {@see CrmRecord} is one.
 */
final readonly class CrmCredentials
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?string $accountId = null,
        public ?string $accountName = null,
    ) {}
}
