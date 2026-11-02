<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\Auth\Actions\ExchangeCredentialsForTokenAction;
use Spatie\LaravelData\Data;

/**
 * One attempt to exchange credentials for a personal access token (Phase 18).
 *
 * Both second-factor fields are nullable because a client cannot know whether the account needs
 * one until it has asked — the first post carries neither, and the `423` names what to send next.
 * Which of them is enough is {@see ExchangeCredentialsForTokenAction}'s answer.
 */
final class TokenExchangeData extends Data
{
    /**
     * @param  list<string>  $abilities  The scopes asked for; `['*']` for a full-access token.
     */
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $deviceName,
        public readonly array $abilities = ['*'],
        /** Six digits from the authenticator app. */
        public readonly ?string $code = null,
        /** One of the one-time codes written down when two-factor was set up. */
        public readonly ?string $recoveryCode = null,
    ) {}
}
